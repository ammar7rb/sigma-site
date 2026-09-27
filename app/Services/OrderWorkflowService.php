<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderLogisticsEvent;
use App\Models\OrderOperationalAlert;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderWorkflowService
{
    public const ACTIVE_QUEUE_STATUSES = ['pending', 'confirmed', 'processing', 'out_for_delivery'];

    public const TRANSITIONS = [
        'pending' => ['confirmed', 'processing', 'canceled'],
        'confirmed' => ['processing', 'out_for_delivery', 'canceled'],
        'processing' => ['out_for_delivery', 'delivered', 'failed', 'canceled'],
        'out_for_delivery' => ['delivered', 'returned', 'failed', 'canceled'],
        'delivered' => ['returned'],
        'returned' => [],
        'failed' => ['processing', 'canceled'],
        'canceled' => [],
    ];

    public function assertTransition(string $from, string $to, string $actorType = 'admin', bool $override = false, ?string $reason = null): void
    {
        if ($from === $to || in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return;
        }

        if ($actorType === 'admin' && $override && trim((string) $reason) !== '') {
            return;
        }

        throw new DomainException('order_status_transition_not_allowed');
    }

    public function refreshSellerQueue(int $sellerId): void
    {
        $ordersQuery = Order::query()
            ->where('seller_is', 'seller')
            ->where('seller_id', $sellerId)
            ->whereIn('order_status', self::ACTIVE_QUEUE_STATUSES);

        // Some legacy/test databases predate the commerce flow columns. The
        // queue must remain backward compatible while excluding locked orders
        // whenever the new contract is available.
        if (Schema::hasColumn('orders', 'commerce_flow_status')) {
            $ordersQuery->where(fn ($flow) => $flow
                ->whereNull('commerce_flow_status')
                ->orWhereNotIn('commerce_flow_status', ['seller_insurance_pending', 'seller_insurance_under_review']));
        }

        $orders = $ordersQuery
            ->orderBy('seller_queue_priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id']);

        foreach ($orders as $index => $order) {
            Order::query()->whereKey($order->id)->update(['seller_queue_position' => $index + 1]);
        }

        Order::query()
            ->where('seller_is', 'seller')
            ->where('seller_id', $sellerId)
            ->whereNotIn('order_status', self::ACTIVE_QUEUE_STATUSES)
            ->whereNotNull('seller_queue_position')
            ->update(['seller_queue_position' => null]);
    }

    public function overrideQueue(Order $order, int $priority, int $adminId, string $reason): Order
    {
        if ($order->seller_is !== 'seller' || !$order->seller_id || trim($reason) === '') {
            throw new DomainException('seller_queue_override_invalid');
        }

        $order->update([
            'seller_queue_priority' => max(1, min(9999, $priority)),
            'seller_queue_override_at' => now(),
            'seller_queue_override_by' => $adminId,
            'seller_queue_override_reason' => $reason,
        ]);
        $this->refreshSellerQueue((int) $order->seller_id);

        OrderLogisticsEvent::query()->create([
            'order_id' => $order->id,
            'event_type' => 'seller_queue_overridden',
            'order_status' => $order->order_status,
            'shipping_status' => $order->shipping_operational_status,
            'actor_type' => 'admin',
            'actor_id' => $adminId,
            'reason_code' => 'manual_priority',
            'note' => $reason,
            'metadata' => ['priority' => $priority, 'queue_position' => $order->fresh()->seller_queue_position],
        ]);

        return $order->fresh();
    }

    public function queueState(Order $order): array
    {
        return [
            'position' => $order->seller_queue_position,
            'priority' => $order->seller_queue_priority,
            'is_current' => (int) $order->seller_queue_position === 1,
            'sound_enabled' => $this->soundEnabled(),
            'blocked_by' => $order->operational_blocked_by,
            'block_reason' => $order->operational_block_reason,
        ];
    }

    public function monitorSellerDelays(?Carbon $now = null): array
    {
        $now ??= now();
        $created = ['warning' => 0, 'escalation' => 0];
        Order::query()->where('seller_is', 'seller')->whereNotNull('seller_id')->whereIn('order_status', self::ACTIVE_QUEUE_STATUSES)
            ->distinct()->pluck('seller_id')->each(fn ($sellerId) => $this->refreshSellerQueue((int) $sellerId));
        Order::query()
            ->where('seller_is', 'seller')
            ->whereNotNull('seller_id')
            ->whereIn('order_status', self::ACTIVE_QUEUE_STATUSES)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($now, &$created): void {
                foreach ($orders as $order) {
                    $minutes = $this->delayMinutes($order->order_status);
                    if ($minutes <= 0) {
                        continue;
                    }
                    $startedAt = $order->operational_status_updated_at ?: $order->updated_at ?: $order->created_at;
                    $dueAt = $startedAt->copy()->addMinutes($minutes);
                    if ($dueAt->gt($now)) {
                        continue;
                    }
                    $level = $now->gte($dueAt->copy()->addMinutes($minutes)) ? 'escalation' : 'warning';
                    $key = implode(':', ['seller-delay', $order->id, $order->order_status, $level]);
                    $alert = OrderOperationalAlert::query()->firstOrCreate(
                        ['idempotency_key' => $key],
                        [
                            'order_id' => $order->id,
                            'seller_id' => $order->seller_id,
                            'alert_type' => 'seller_delay',
                            'level' => $level,
                            'order_status' => $order->order_status,
                            'due_at' => $dueAt,
                            'notified_at' => $now,
                            'metadata' => ['minutes' => $minutes, 'queue_position' => $order->seller_queue_position],
                        ]
                    );
                    if ($alert->wasRecentlyCreated) {
                        $created[$level]++;
                        $this->notifySeller($order, $level);
                    }
                }
            });

        return $created;
    }

    public function expireCustomerConfirmations(?Carbon $now = null): int
    {
        $now ??= now();
        $ids = Order::query()
            ->where('customer_delivery_confirmation_status', 'pending')
            ->whereNotNull('customer_delivery_confirmation_due_at')
            ->where('customer_delivery_confirmation_due_at', '<=', $now)
            ->pluck('id');
        $count = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, &$count): void {
                $order = Order::query()->lockForUpdate()->find($id);
                if (!$order || $order->customer_delivery_confirmation_status !== 'pending'
                    || !$order->customer_delivery_confirmation_due_at?->lte($now)) {
                    return;
                }
                $order->update([
                    'customer_delivery_confirmation_status' => 'expired',
                    'customer_delivery_confirmation_expired_at' => $now,
                ]);
                OrderLogisticsEvent::query()->firstOrCreate(
                    ['event_key' => 'customer-confirmation-expired:' . $order->id],
                    [
                        'order_id' => $order->id,
                        'event_type' => 'customer_receipt_confirmation_expired',
                        'order_status' => $order->order_status,
                        'shipping_status' => $order->shipping_operational_status,
                        'responsible_party' => $order->shipping_responsible_party,
                        'actor_type' => 'system',
                        'reason_code' => 'confirmation_deadline_elapsed',
                        'note' => 'The confirmation window expired; complaint and return rights remain governed by policy.',
                        'metadata' => ['due_at' => $order->customer_delivery_confirmation_due_at?->toIso8601String()],
                    ]
                );
                $count++;
            });
        }

        return $count;
    }

    public function resolveAlertsForStatus(Order $order, string $previousStatus): void
    {
        if (!Schema::hasTable('order_operational_alerts')) {
            return;
        }
        OrderOperationalAlert::query()
            ->where('order_id', $order->id)
            ->where('alert_type', 'seller_delay')
            ->where('order_status', $previousStatus)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    private function delayMinutes(string $status): int
    {
        $defaults = ['pending' => 30, 'confirmed' => 60, 'processing' => 180, 'out_for_delivery' => 1440];
        $configured = null;
        if (Schema::hasTable('business_settings')) {
            $configured = BusinessSetting::query()->where('type', 'seller_order_delay_minutes')->value('value');
        }
        $configured = is_string($configured) ? json_decode($configured, true) : $configured;
        return max(0, (int) (($configured[$status] ?? null) ?? ($defaults[$status] ?? 0)));
    }

    private function soundEnabled(): bool
    {
        if (!Schema::hasTable('business_settings')) {
            return true;
        }
        $value = BusinessSetting::query()->where('type', 'seller_order_sound_alert_enabled')->value('value');
        return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function notifySeller(Order $order, string $level): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }
        $locale = strtolower((string) ($order->seller?->app_language ?? 'en'));
        $locale = in_array($locale, ['ar', 'ar-sa', 'sa'], true) ? 'sa' : 'en';
        $translations = include(base_path('resources/lang/' . $locale . '/new-messages.php'));
        $titleKey = $level === 'escalation' ? 'seller_order_delay_escalation' : 'seller_order_delay_warning';
        Notification::query()->create([
            'sent_by' => 'admin',
            'sent_to' => 'seller',
            'seller_id' => $order->seller_id,
            'title' => $translations[$titleKey] ?? 'Seller order delay',
            'description' => ($translations['seller_order_delay_notification'] ?? 'Order processing deadline elapsed') . ' #' . $order->id,
            'notification_count' => 1,
            'status' => 1,
        ]);
    }
}
