<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShippingDecision;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;

class OrderShippingDecisionService
{
    public const MODE_PENDING = 'pending';
    public const MODE_ADMIN = 'admin_shipping';
    public const MODE_SELLER = 'seller_shipping';

    public function quickAssign(Order $order, string $assignment, ?int $adminId = null, ?string $note = null): OrderShippingDecision
    {
        if ($assignment === self::MODE_SELLER) {
            return $this->assign($order, [
                'mode' => self::MODE_SELLER,
                'note' => $note ?: 'Quick assignment from logistics center.',
            ], $adminId);
        }

        if (preg_match('/^admin_method:(\d+)$/', $assignment, $matches)) {
            return $this->assign($order, [
                'mode' => self::MODE_ADMIN,
                'shipping_method_id' => (int) $matches[1],
                'note' => $note ?: 'Quick assignment from logistics center.',
            ], $adminId);
        }

        throw ValidationException::withMessages([
            'assignment' => translate('shipping_party_selection_is_invalid'),
        ]);
    }

    public function assign(Order $order, array $data, ?int $adminId = null): OrderShippingDecision
    {
        $mode = $data['mode'] ?? null;
        if (!in_array($mode, [self::MODE_ADMIN, self::MODE_SELLER], true)) {
            throw ValidationException::withMessages(['mode' => translate('shipping_mode_required')]);
        }

        return DB::transaction(function () use ($order, $data, $mode, $adminId): OrderShippingDecision {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $customerCost = array_key_exists('customer_cost', $data) && $data['customer_cost'] !== '' && $data['customer_cost'] !== null ? (float)$data['customer_cost'] : null;
            $sellerCost = array_key_exists('seller_cost', $data) && $data['seller_cost'] !== '' && $data['seller_cost'] !== null ? (float)$data['seller_cost'] : null;
            $sellerEntitlement = array_key_exists('seller_entitlement', $data) && $data['seller_entitlement'] !== '' && $data['seller_entitlement'] !== null ? (float)$data['seller_entitlement'] : null;
            $shippingMethod = null;
            if ($mode === self::MODE_ADMIN && !empty($data['shipping_method_id'])) {
                $shippingMethod = ShippingMethod::query()
                    ->where('id', $data['shipping_method_id'])
                    ->where('creator_type', 'admin')
                    ->where('status', 1)
                    ->first();
                if (!$shippingMethod) {
                    throw ValidationException::withMessages(['shipping_method_id' => translate('shipping_company_required')]);
                }
                $customerCost ??= (float) $shippingMethod->cost;
            } elseif ($mode === self::MODE_ADMIN && trim((string)($data['service_name'] ?? '')) === '') {
                throw ValidationException::withMessages(['shipping_method_id' => translate('shipping_company_required')]);
            }

            $previousMode = $lockedOrder->shipping_fulfillment_mode ?: self::MODE_PENDING;
            $shipmentReference = $lockedOrder->shipment_reference ?: $this->shipmentReference($lockedOrder);
            $pickupSnapshot = $lockedOrder->pickup_address_snapshot ?: $this->pickupSnapshot($lockedOrder);
            $customerSnapshot = $lockedOrder->customer_address_snapshot ?: $this->customerSnapshot($lockedOrder);
            $lockedOrder->fill($this->supportedOrderUpdates([
                'shipping_fulfillment_mode' => $mode,
                'shipping_assignment_status' => 'assigned',
                'shipping_customer_cost' => $customerCost,
                'shipping_seller_cost' => $sellerCost,
                'shipping_seller_entitlement' => $sellerEntitlement,
                'shipping_decided_at' => now(),
                'shipping_decided_by' => $adminId,
                'shipping_decision_note' => $data['note'] ?? null,
                // Keep existing reports compatible with the platform's terminology.
                'shipping_responsibility' => $mode === self::MODE_SELLER ? 'sellerwise_shipping' : 'inhouse_shipping',
                'shipping_method_id' => $shippingMethod?->id,
                'shipping_cost' => $customerCost ?? $lockedOrder->shipping_cost,
                'delivery_service_name' => $shippingMethod?->title ?? ($data['service_name'] ?? $lockedOrder->delivery_service_name),
                'third_party_delivery_tracking_id' => $data['tracking_number'] ?? $lockedOrder->third_party_delivery_tracking_id,
                'shipment_reference' => $shipmentReference,
                'pickup_address_snapshot' => $pickupSnapshot,
                'customer_address_snapshot' => $customerSnapshot,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? $lockedOrder->expected_delivery_date,
            ]));
            $lockedOrder->save();

            $decision = OrderShippingDecision::query()->create([
                'order_id' => $lockedOrder->getKey(),
                'admin_id' => $adminId,
                'previous_mode' => $previousMode,
                'mode' => $mode,
                'assignment_status' => 'assigned',
                'customer_cost' => $customerCost,
                'seller_cost' => $sellerCost,
                'seller_entitlement' => $sellerEntitlement,
                'service_name' => $shippingMethod?->title ?? ($data['service_name'] ?? null),
                'tracking_number' => $data['tracking_number'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            app(OrderLogisticsService::class)->recordAssignment($lockedOrder, $adminId, $data['note'] ?? null);
            return $decision;
        });
    }

    public function ensureShipmentIdentity(Order $order): Order
    {
        if (!Schema::hasColumn('orders', 'shipment_reference')) {
            return $order;
        }
        if ($order->shipment_reference) {
            return $order;
        }
        $order->update([
            'shipment_reference' => $this->shipmentReference($order),
            'pickup_address_snapshot' => $this->pickupSnapshot($order),
            'customer_address_snapshot' => $this->customerSnapshot($order),
        ]);
        return $order->fresh();
    }

    private function shipmentReference(Order $order): string
    {
        return 'SHP-' . now()->format('Ym') . '-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
    }

    private function pickupSnapshot(Order $order): array
    {
        $order->loadMissing('seller.shop');
        $shop = $order->seller?->shop;
        return array_filter([
            'source' => $order->seller_is === 'seller' ? 'seller_shop' : 'company',
            'seller_id' => $order->seller_id,
            'address' => $shop?->address,
            'contact' => $shop?->contact,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function customerSnapshot(Order $order): array
    {
        $address = $order->shipping_address_data;
        if (is_object($address)) {
            $address = get_object_vars($address);
        } elseif (is_string($address)) {
            $address = json_decode($address, true) ?: ['address' => $address];
        }
        return array_merge(['source' => 'order_shipping_address'], (array) $address);
    }

    private function supportedOrderUpdates(array $updates): array
    {
        return array_intersect_key($updates, array_flip(Schema::getColumnListing('orders')));
    }
}
