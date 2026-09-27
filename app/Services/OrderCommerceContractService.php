<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderInsurance;
use App\Models\SellerOrderInsurance;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderCommerceContractService
{
    public function __construct(private readonly OrderCommerceFeatureService $features)
    {
    }

    /** @return array<string, mixed> */
    public function customerInsurancePayload(Order $order, ?OrderInsurance $insurance = null): array
    {
        $insurance ??= $order->relationLoaded('insurance') ? $order->insurance : null;

        return [
            'contract_version' => $this->features->contractVersion(),
            'flow_status' => $order->commerce_flow_status,
            'order_reference' => $this->fullReference($order),
            'purchase_amount' => (float) $order->order_amount,
            'insurance' => [
                'amount' => (float) ($insurance?->amount ?? 0),
                'order_amount_snapshot' => (float) ($insurance?->order_amount ?? $order->order_amount),
                'threshold_amount' => (float) ($insurance?->threshold_amount ?? 0),
                'calculation_type' => $insurance?->calculation_type,
                'calculation_value' => (float) ($insurance?->calculation_value ?? 0),
                'payment_status' => $insurance?->payment_status,
                'status' => $insurance?->status,
                'payment_due_at' => $insurance?->payment_due_at?->toIso8601String(),
                'balance_use_policy' => $insurance?->balance_use_policy ?: 'insurance_only',
            ],
            'order_is_suspended_until_insurance_payment' => true,
            'support_available' => true,
            'purchase_refund' => [
                'status' => $insurance?->purchase_refund_status,
                'due_at' => $insurance?->purchase_refund_due_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * This contract intentionally contains no customer, address, phone,
     * products, payment evidence or shipping details.
     *
     * @return array<string, mixed>
     */
    public function restrictedSellerPayload(Order $order, ?SellerOrderInsurance $insurance = null): array
    {
        $insurance ??= $order->relationLoaded('sellerOrderInsurance') ? $order->sellerOrderInsurance : null;

        return [
            'contract_version' => $this->features->contractVersion(),
            'details_locked' => true,
            'restricted_access_token' => $this->restrictedAccessToken($order),
            'order_reference' => $this->maskedReference($order),
            'order_last_three_digits' => str_pad(substr((string) $order->id, -3), 3, '0', STR_PAD_LEFT),
            'order_amount' => (float) $order->order_amount,
            'flow_status' => $order->commerce_flow_status,
            'created_at' => $order->created_at?->toIso8601String(),
            'seller_insurance' => [
                'amount' => (float) ($insurance?->amount ?? 0),
                'status' => $insurance?->status,
                'payment_status' => $insurance?->payment_status,
                'expires_at' => $insurance?->expires_at?->toIso8601String(),
                'amount_source' => $insurance?->amount_source,
                'balance_use_policy' => $insurance?->balance_use_policy ?: 'insurance_only',
            ],
            'support_available' => true,
        ];
    }

    public function restrictedAccessToken(Order $order): ?string
    {
        if (!Schema::hasColumn('orders', 'seller_restricted_access_token')) {
            return null;
        }
        if (!$order->seller_restricted_access_token) {
            $order->forceFill(['seller_restricted_access_token' => (string) Str::uuid()])->saveQuietly();
        }
        return (string) $order->seller_restricted_access_token;
    }

    /** @return array<string, mixed> */
    public function adminPayload(Order $order): array
    {
        return [
            'contract_version' => $this->features->contractVersion(),
            'flow_version' => $order->commerce_flow_version,
            'flow_status' => $order->commerce_flow_status,
            'order_reference' => $this->fullReference($order),
            'order_amount' => (float) $order->order_amount,
            'admin_review' => [
                'status' => $order->admin_order_review_status,
                'reviewed_by' => $order->admin_order_reviewed_by,
                'reviewed_at' => $order->admin_order_reviewed_at?->toIso8601String(),
                'note' => $order->admin_order_review_note,
            ],
            'shipping_assignment' => [
                'status' => $order->shipping_assignment_status,
                'responsible_party' => $order->shipping_responsible_party,
                'customer_cost' => (float) ($order->shipping_customer_cost ?? 0),
                'seller_cost' => (float) ($order->shipping_seller_cost ?? 0),
            ],
            'customer_insurance' => $order->relationLoaded('insurance') && $order->insurance
                ? ['id' => $order->insurance->id, 'amount' => (float) $order->insurance->amount, 'status' => $order->insurance->status]
                : null,
            'seller_insurance' => $order->relationLoaded('sellerOrderInsurance') && $order->sellerOrderInsurance
                ? ['id' => $order->sellerOrderInsurance->id, 'amount' => (float) $order->sellerOrderInsurance->amount, 'status' => $order->sellerOrderInsurance->status, 'amount_source' => $order->sellerOrderInsurance->amount_source]
                : null,
        ];
    }

    private function fullReference(Order $order): string
    {
        return 'ORD-' . $order->id;
    }

    private function maskedReference(Order $order): string
    {
        return '***' . str_pad(substr((string) $order->id, -3), 3, '0', STR_PAD_LEFT);
    }
}
