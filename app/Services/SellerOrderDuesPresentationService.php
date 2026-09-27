<?php
namespace App\Services;

use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\SellerSettlement;

class SellerOrderDuesPresentationService
{
    public function page(int $sellerId, int $page = 1)
    {
        $orders = Order::where('seller_id', $sellerId)->where('seller_is', 'seller')
            ->with('sellerOrderInsurance')->latest('id')->paginate(20, ['*'], 'orders_page', max(1, $page));
        $ids = $orders->getCollection()->pluck('id');
        $settlements = SellerSettlement::where('seller_id', $sellerId)->whereIn('order_id', $ids)->get()->keyBy('order_id');
        $transactions = OrderTransaction::where('seller_id', $sellerId)->where('seller_is', 'seller')->whereIn('order_id', $ids)->get()->keyBy('order_id');
        return $orders->through(function ($order) use ($settlements, $transactions) {
            $insurance = $order->sellerOrderInsurance;
            $settlement = $settlements->get($order->id);
            $transaction = $transactions->get($order->id);
            return [
                'order_id' => $order->id, 'status' => $order->order_status,
                'sales_amount' => $transaction ? (float) $transaction->seller_amount : null,
                'sales_due_at' => $order->sales_settlement_due_at?->toIso8601String() ?? $settlement?->due_at?->toIso8601String(),
                'settlement_status' => $settlement?->status,
                'shipping_amount' => $order->shipping_responsibility === 'sellerwise_shipping'
                    ? (float) ($order->shipping_seller_entitlement ?? $order->seller_shipping_allocation ?? $transaction?->delivery_charge ?? 0) : 0.0,
                'shipping_due_at' => $order->shipping_settlement_due_at?->toIso8601String(),
                'insurance_amount' => (float) ($insurance?->amount ?? 0), 'insurance_status' => $insurance?->status,
                'insurance_reuse_at' => $insurance?->reusable_at?->toIso8601String(),
                'insurance_released_at' => $insurance?->reusable_released_at?->toIso8601String(),
            ];
        });
    }
}
