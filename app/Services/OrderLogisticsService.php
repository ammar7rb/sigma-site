<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OrderLogisticsEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderLogisticsService
{
    public const RESPONSIBLE_PARTIES = ['company', 'seller', 'carrier', 'customer'];

    public function statusForOrderStatus(string $orderStatus): string
    {
        return match ($orderStatus) {
            'out_for_delivery' => 'in_transit',
            'delivered' => 'delivered',
            'returned' => 'returned',
            'failed', 'canceled' => 'closed',
            'processing', 'confirmed' => 'preparing',
            default => 'pending_assignment',
        };
    }

    public function confirmationDays(): int
    {
        return max(1, (int) (BusinessSetting::query()
            ->where('type', 'customer_delivery_confirmation_days')
            ->value('value') ?? 3));
    }

    public function recordAssignment(Order $order, ?int $adminId = null, ?string $note = null): void
    {
        $responsibleParty = $order->shipping_fulfillment_mode === OrderShippingDecisionService::MODE_SELLER
            ? 'seller'
            : 'company';

        $order->update([
            'shipping_responsible_party' => $responsibleParty,
            'shipping_operational_status' => $order->order_status === 'out_for_delivery'
                ? 'in_transit'
                : 'assigned',
        ]);

        $this->record($order->fresh(), 'shipping_assigned', 'admin', $adminId, $note, $responsibleParty);
    }

    public function recordOrderStatus(
        Order $order,
        string $orderStatus,
        string $actorType = 'admin',
        ?int $actorId = null,
        ?string $previousStatus = null,
        bool $override = false,
        ?string $reason = null,
    ): void
    {
        DB::transaction(function () use ($order, $orderStatus, $actorType, $actorId, $previousStatus, $override, $reason): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $previousStatus ??= $lockedOrder->order_status;
            app(OrderWorkflowService::class)->assertTransition($previousStatus, $orderStatus, $actorType, $override, $reason);
            $status = $this->statusForOrderStatus($orderStatus);
            $updates = [
                'shipping_operational_status' => $status,
                'operational_status_updated_at' => now(),
            ];

            if ($previousStatus !== $orderStatus) {
                $updates['operational_blocked_by'] = null;
                $updates['operational_block_reason'] = null;
            }

            if ($orderStatus === 'delivered' && !$lockedOrder->is_guest) {
                $updates['customer_delivery_confirmation_status'] = 'pending';
                $updates['customer_delivery_confirmation_due_at'] = now()->addDays($this->confirmationDays());
                $updates['customer_delivery_confirmed_at'] = null;
            }

            if ($orderStatus === 'returned') {
                $updates['return_started_at'] = $lockedOrder->return_started_at ?: now();
                $updates['return_completed_at'] = now();
            }

            $lockedOrder->update($this->supportedOrderUpdates($updates));
            $fresh = $lockedOrder->fresh();
            $this->record(
                $fresh,
                $override ? 'order_status_admin_override' : 'shipping_status_updated',
                $actorType,
                $actorId,
                $reason,
                $lockedOrder->shipping_responsible_party,
                ['previous_order_status' => $previousStatus, 'order_status' => $orderStatus, 'reason_code' => $override ? 'admin_override' : null]
            );
            app(OrderWorkflowService::class)->resolveAlertsForStatus($fresh, $previousStatus);
            if ($fresh->seller_is === 'seller' && $fresh->seller_id) {
                app(OrderWorkflowService::class)->refreshSellerQueue((int) $fresh->seller_id);
            }
        });
    }

    public function block(Order $order, string $blockedBy, string $reason, string $actorType = 'admin', ?int $actorId = null): void
    {
        $order->update(['operational_blocked_by' => $blockedBy, 'operational_block_reason' => $reason]);
        $this->record($order->fresh(), 'order_operational_blocked', $actorType, $actorId, $reason, $order->shipping_responsible_party, [
            'blocked_by' => $blockedBy,
            'reason_code' => 'operational_block',
        ]);
    }

    public function registerReturn(Order $order, array $data, ?int $adminId = null): void
    {
        DB::transaction(function () use ($order, $data, $adminId): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $party = $data['return_responsible_party'];
            if (!in_array($party, self::RESPONSIBLE_PARTIES, true)) {
                throw new \InvalidArgumentException('Invalid return responsibility');
            }

            $lockedOrder->update([
                'return_responsible_party' => $party,
                'return_shipping_cost' => $data['return_shipping_cost'] ?? null,
                'return_tracking_number' => $data['return_tracking_number'] ?? null,
                'return_reason' => $data['return_reason'],
                'return_started_at' => $lockedOrder->return_started_at ?: now(),
                'shipping_operational_status' => 'return_in_transit',
            ]);

            $this->record($lockedOrder->fresh(), 'return_registered', 'admin', $adminId, $data['return_reason'], $party);
        });
    }

    public function confirmCustomerReceipt(Order $order, int $customerId): void
    {
        DB::transaction(function () use ($order, $customerId): void {
            $lockedOrder = Order::query()->lockForUpdate()
                ->where('id', $order->id)
                ->where('customer_id', $customerId)
                ->where('is_guest', false)
                ->firstOrFail();

            if ($lockedOrder->order_status !== 'delivered' || $lockedOrder->customer_delivery_confirmation_status !== 'pending') {
                throw new \DomainException(translate('customer_receipt_confirmation_not_available'));
            }

            $lockedOrder->update([
                'customer_delivery_confirmation_status' => 'confirmed',
                'customer_delivery_confirmed_at' => now(),
            ]);
            $this->record($lockedOrder->fresh(), 'customer_receipt_confirmed', 'customer', $customerId, null, $lockedOrder->shipping_responsible_party);
        });
    }

    public function record(
        Order $order,
        string $eventType,
        ?string $actorType,
        ?int $actorId,
        ?string $note,
        ?string $responsibleParty,
        array $context = [],
    ): void
    {
        $payload = [
            'order_id' => $order->id,
            'event_type' => $eventType,
            'previous_order_status' => $context['previous_order_status'] ?? null,
            'order_status' => $context['order_status'] ?? $order->order_status,
            'blocked_by' => $context['blocked_by'] ?? $order->operational_blocked_by,
            'reason_code' => $context['reason_code'] ?? null,
            'event_key' => $context['event_key'] ?? null,
            'shipping_status' => $order->shipping_operational_status,
            'responsible_party' => $responsibleParty,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'customer_shipping_cost' => $order->shipping_customer_cost ?? $order->shipping_cost,
            'seller_shipping_cost' => $order->shipping_seller_cost,
            'return_shipping_cost' => $order->return_shipping_cost,
            'tracking_number' => $order->return_tracking_number ?: $order->third_party_delivery_tracking_id,
            'note' => $note,
            'metadata' => array_merge([
                'order_status' => $order->order_status,
                'shipping_mode' => $order->shipping_fulfillment_mode,
                'service_name' => $order->delivery_service_name,
                'shipment_reference' => $order->shipment_reference,
                'shipping_price_snapshot' => $order->shipping_price_snapshot,
            ], $context['metadata'] ?? []),
        ];
        $columns = Schema::getColumnListing('order_logistics_events');
        OrderLogisticsEvent::query()->create(array_intersect_key($payload, array_flip($columns)));
    }

    private function supportedOrderUpdates(array $updates): array
    {
        return array_intersect_key($updates, array_flip(Schema::getColumnListing('orders')));
    }
}
