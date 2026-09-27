<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ShippingMethod;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class ShippingPromiseService
{
    public function __construct(private readonly BusinessCalendarService $calendar)
    {
    }

    public function config(string $key): array
    {
        $key = $key === 'sigma' ? 'sigma' : 'normal';
        $defaults = $key === 'sigma'
            ? ['mode' => 'fixed', 'min' => 6, 'max' => 6]
            : ['mode' => 'fixed', 'min' => 10, 'max' => 10];
        $mode = getWebConfig(name: "shipping_promise_{$key}_mode") ?: $defaults['mode'];
        $min = max(1, (int) (getWebConfig(name: "shipping_promise_{$key}_min_days") ?? $defaults['min']));
        $max = max($min, (int) (getWebConfig(name: "shipping_promise_{$key}_max_days") ?? $defaults['max']));

        if ($mode === 'fixed') {
            $max = $min;
        }

        return ['key' => $key, 'mode' => $mode, 'min_days' => $min, 'max_days' => $max];
    }

    public function promise(string $key, ?Carbon $from = null): array
    {
        $from ??= now();
        $config = $this->config($key);
        $etaFrom = $this->calendar->addBusinessDays($from, $config['min_days']);
        $etaTo = $this->calendar->addBusinessDays($from, $config['max_days']);

        return $config + [
            'eta_from' => $etaFrom->toISOString(),
            'eta_to' => $etaTo->toISOString(),
            'display_date' => $etaFrom->isSameDay($etaTo)
                ? $etaFrom->translatedFormat('l d F Y')
                : $etaFrom->translatedFormat('d F') . ' - ' . $etaTo->translatedFormat('d F Y'),
            'calendar' => $this->calendar->snapshot(),
        ];
    }

    public function forMethod(ShippingMethod|int|string|null $method, ?Carbon $from = null): array
    {
        $method = $method instanceof ShippingMethod ? $method : ShippingMethod::find($method);
        $optionKey = $method ? app(CustomerThreeStepShippingService::class)->getOptionKeyForMethod($method) : null;
        $key = $optionKey === 'sigma' ? 'sigma' : 'normal';

        return $this->promise($key, $from) + [
            'method_id' => $method?->id,
            'method_title' => $method?->title,
        ];
    }

    public function orderDatabaseSnapshot(int|string|null $methodId, ?Carbon $from = null): array
    {
        $snapshot = $this->orderSnapshot($methodId, $from);
        // Query-builder inserts do not apply the Order model's JSON casts.
        $snapshot['shipping_duration_snapshot'] = json_encode(
            $snapshot['shipping_duration_snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        return $snapshot;
    }

    public function orderSnapshot(int|string|null $methodId, ?Carbon $from = null): array
    {
        $from ??= now();
        $promise = $this->forMethod($methodId, $from);
        $salesDueAt = app(SellerSettlementService::class)->calculateDueAt($from->copy());

        return [
            'shipping_promise_key' => $promise['key'],
            'shipping_eta_from' => $promise['eta_from'],
            'shipping_eta_to' => $promise['eta_to'],
            'shipping_duration_snapshot' => $promise,
            // Shipping becomes due with sales by default. An administrator
            // can separate it later, but only with a recorded reason.
            'sales_settlement_due_at' => $salesDueAt->toISOString(),
            'shipping_settlement_due_at' => $salesDueAt->toISOString(),
            'shipping_due_separated' => false,
        ];
    }

    public function updateSettlementDueDate(Order $order, Carbon $dueAt, string $reason, int $adminId): Order
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => translate('reason_is_required')]);
        }

        $salesDue = $order->sales_settlement_due_at ?: $order->shipping_settlement_due_at ?: $dueAt;
        $order->forceFill([
            'sales_settlement_due_at' => $salesDue,
            'shipping_settlement_due_at' => $dueAt,
            'shipping_due_separated' => ! Carbon::parse($salesDue)->equalTo($dueAt),
            'shipping_due_override_reason' => $reason,
        ])->save();

        return $order->refresh();
    }
}
