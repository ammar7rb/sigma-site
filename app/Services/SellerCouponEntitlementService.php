<?php

namespace App\Services;

use App\Models\Seller;
use App\Models\SellerPackageSubscription;
use App\Models\SellerPackageTransaction;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerCouponEntitlementService
{
    public function getSummary(Seller $seller): array
    {
        $subscription = $this->activeSubscriptionQuery($seller)->first();
        if (! $subscription) {
            return [
                'allowed' => false,
                'limit' => 0,
                'used' => 0,
                'adjustment' => 0,
                'remaining' => 0,
            ];
        }

        $total = max(0, (int) $subscription->coupon_limit + (int) $subscription->coupon_adjustment_limit);

        return [
            'allowed' => $total > (int) $subscription->used_coupon_limit,
            'limit' => (int) $subscription->coupon_limit,
            'used' => (int) $subscription->used_coupon_limit,
            'adjustment' => (int) $subscription->coupon_adjustment_limit,
            'remaining' => max(0, $total - (int) $subscription->used_coupon_limit),
            'subscription_id' => $subscription->id,
        ];
    }

    public function consumeCouponQuota(Seller $seller): SellerPackageSubscription
    {
        return DB::transaction(function () use ($seller) {
            $seller = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->activeSubscriptionQuery($seller)->lockForUpdate()->first();
            if (! $subscription) {
                throw new DomainException('active_seller_package_is_required_to_create_coupons');
            }

            $total = max(0, (int) $subscription->coupon_limit + (int) $subscription->coupon_adjustment_limit);
            if ($total <= (int) $subscription->used_coupon_limit) {
                throw new DomainException('seller_coupon_quota_has_been_reached');
            }

            $subscription->increment('used_coupon_limit');
            $subscription->refresh();

            // Record every coupon entitlement use so the admin can audit the seller's package quota.
            SellerPackageTransaction::create([
                'seller_id' => $seller->id,
                'seller_package_subscription_id' => $subscription->id,
                'seller_package_id' => $subscription->seller_package_id,
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => SellerPackageTransaction::TYPE_QUOTA_USAGE,
                'quota_type' => SellerPackageTransaction::QUOTA_COUPON,
                'debit' => 1,
                'balance_after' => max(0, $total - (int) $subscription->used_coupon_limit),
                'reference' => 'seller-coupon-'.Str::uuid(),
                'note' => 'Seller coupon quota used.',
            ]);

            return $subscription;
        });
    }

    private function activeSubscriptionQuery(Seller $seller)
    {
        return $seller->packageSubscriptions()
            ->where('status', SellerPackageSubscription::STATUS_ACTIVE)
            ->where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id');
    }
}
