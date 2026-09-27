<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SellerLedgerEntry;
use App\Models\SellerOrderInsurance;
use App\Models\SellerSettlement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SellerFinancePresentationService
{
    public function __construct(private readonly SellerLedgerService $ledger) {}

    public function overview(int $sellerId, int $limit = 50, int $page = 1, ?string $category = null): array
    {
        $summary = $this->ledger->summary($sellerId);
        $entryQuery = SellerLedgerEntry::query()->where('seller_id', $sellerId)
            ->when(in_array($category, ['insurance', 'shipping'], true), fn ($query) => $query->where('reporting_category', $category));
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);
        $total = (clone $entryQuery)->count();
        $entries = $entryQuery->latest('id')->offset(($page - 1) * $limit)->limit($limit)->get();
        $orderIds = $entries->map(fn ($entry) => (int) data_get($entry->metadata, 'order_id', 0))->filter()->unique();
        $orderColumns = ['id', 'shipment_reference', 'shipping_operational_status', 'shipping_responsibility', 'shipping_customer_cost', 'shipping_seller_cost', 'shipping_seller_entitlement', 'shipping_settlement_due_at'];
        $orderColumns = Schema::hasTable('orders') ? array_values(array_filter($orderColumns, fn ($column) => Schema::hasColumn('orders', $column))) : [];
        $orders = $orderColumns ? Order::query()->whereIn('id', $orderIds)->get($orderColumns)->keyBy('id') : collect();
        $settlements = Schema::hasTable('seller_settlements')
            ? SellerSettlement::query()->where('seller_id', $sellerId)->whereIn('order_id', $orderIds)->get(['id', 'order_id', 'status', 'due_at'])->keyBy('order_id')
            : collect();
        $insurances = Schema::hasTable('seller_order_insurances')
            ? SellerOrderInsurance::query()->where('seller_id', $sellerId)->whereIn('order_id', $orderIds)->get(['order_id', 'amount', 'status', 'paid_at', 'reusable_at'])->keyBy('order_id')
            : collect();

        $records = $entries->map(function (SellerLedgerEntry $entry) use ($orders, $settlements, $insurances): array {
            $category = $entry->reporting_category ?: $this->ledger->reportingCategory($entry->event_type);
            $orderId = (int) data_get($entry->metadata, 'order_id', 0);
            $order = $orders->get($orderId);
            $settlement = $settlements->get($orderId);
            $insurance = $insurances->get($orderId);
            return [
                'id' => $entry->id,
                'category' => $category,
                'bucket' => $entry->bucket,
                'direction' => $entry->direction,
                'amount' => (float) $entry->amount,
                'event_type' => $entry->event_type,
                'reference_code' => $entry->reference_code ?: $this->ledger->referenceCode($entry->group_key, $entry->reference_type, $entry->reference_id, $entry->metadata ?: []),
                'order_id' => $orderId ?: null,
                'shipment_reference' => $order?->shipment_reference,
                'shipping_status' => $order?->shipping_operational_status,
                'shipping_customer_cost' => (float) ($order?->shipping_customer_cost ?? 0),
                'shipping_seller_cost' => (float) ($order?->shipping_seller_cost ?? 0),
                'shipping_seller_entitlement' => (float) ($order?->shipping_seller_entitlement ?? 0),
                'shipping_due_at' => $order?->shipping_settlement_due_at ? \Carbon\Carbon::parse($order->shipping_settlement_due_at)->toIso8601String() : null,
                'shipping_responsible' => ($order?->shipping_responsibility ?? null) === 'sellerwise_shipping',
                'seller_insurance_amount' => (float) ($insurance?->amount ?? 0),
                'seller_insurance_status' => $insurance?->status,
                'seller_insurance_paid_at' => $insurance?->paid_at?->toIso8601String(),
                'seller_insurance_reuse_at' => $insurance?->reusable_at?->toIso8601String(),
                'settlement_status' => $settlement?->status,
                'due_at' => $settlement?->due_at?->toIso8601String(),
                'created_at' => $entry->created_at?->toIso8601String(),
            ];
        });

        return [
            'pagination' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $limit)), 'total' => $total],
            'summary' => $summary,
            'tabs' => [
                'balance' => $records->values()->all(),
                'insurance' => $records->where('category', 'insurance')->values()->all(),
                'shipping' => $records->where('category', 'shipping')->values()->all(),
            ],
            'next_collection_at' => Schema::hasTable('seller_settlements') ? SellerSettlement::query()->where('seller_id', $sellerId)
                ->where('status', SellerSettlement::STATUS_HELD)->whereNotNull('due_at')->min('due_at') : null,
        ];
    }
}
