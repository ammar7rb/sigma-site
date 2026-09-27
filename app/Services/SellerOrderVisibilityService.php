<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SellerOrderInsurance;
use Illuminate\Support\Collection;

/** Keeps new v3 orders invisible to the seller until the admin release. */
class SellerOrderVisibilityService
{
    /** Seller may see either the complete order or its restricted insurance gate. */
    public function canSellerAccess(Order|array $order): bool
    {
        if (in_array(data_get($order, 'admin_order_review_status'), [
            'pending_admin_review', 'assignment_in_progress', 'waiting_customer_post_purchase_payment',
        ], true)) return false;

        $flow = data_get($order, 'commerce_flow_version');
        if (! in_array($flow, [PostPurchaseInvoiceService::CONTRACT_VERSION, config('order_commerce.new_flow_version')], true)) {
            return true;
        }

        return data_get($order, 'admin_order_review_status') === 'approved'
            && in_array(data_get($order, 'commerce_flow_status'), [
                \App\Support\Commerce\OrderCommerceState::SELLER_INSURANCE_PENDING,
                \App\Support\Commerce\OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW,
                \App\Support\Commerce\OrderCommerceState::RELEASED_TO_SELLER,
                \App\Support\Commerce\OrderCommerceState::FULFILLMENT_IN_PROGRESS,
                \App\Support\Commerce\OrderCommerceState::COMPLETED,
                \App\Support\Commerce\OrderCommerceState::CANCELLED,
            ], true);
    }

    public function canSellerView(Order|array $order): bool
    {
        if (in_array(data_get($order, 'admin_order_review_status'), [
            'pending_admin_review', 'assignment_in_progress', 'waiting_customer_post_purchase_payment',
        ], true)) return false;
        $flow = data_get($order, 'commerce_flow_version');
        $requiresRestrictedRelease = in_array($flow, [
            PostPurchaseInvoiceService::CONTRACT_VERSION,
            config('order_commerce.new_flow_version'),
        ], true);
        if (! $requiresRestrictedRelease) {
            return true;
        }

        if ($flow === PostPurchaseInvoiceService::CONTRACT_VERSION
            && data_get($order, 'post_purchase_status') !== PostPurchaseInvoiceService::ORDER_STATUS_RELEASED) {
            return false;
        }

        $insurance = $order instanceof Order
            ? $order->sellerOrderInsurance()->first()
            : SellerOrderInsurance::query()
                ->where('order_id', (int) data_get($order, 'id'))
                ->where('seller_id', (int) data_get($order, 'seller_id'))
                ->first();

        return $insurance !== null
            && app(SellerOrderInsuranceService::class)->canViewDetails($insurance);
    }

    public function visible(Collection $orders): Collection
    {
        return $orders->filter(fn ($order) => $this->canSellerView($order))->values();
    }

    public function accessible(Collection $orders): Collection
    {
        return $orders->filter(fn ($order) => $this->canSellerAccess($order))->values();
    }
}
