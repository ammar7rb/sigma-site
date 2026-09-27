<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerPackageSubscription;
use App\Models\SellerPackageTransaction;
use App\Models\SellerProductEntitlement;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerProductEntitlementService
{
    public function getSummary(Seller $seller): array
    {
        $subscription = $this->activeSubscriptionQuery($seller)->first();

        return [
            // Product publishing is a core seller capability. Advertising
            // packages only grant promotional placements and never gate product
            // creation, review, publication or lifetime.
            'insurance_satisfied' => true,
            'active_subscription' => $subscription,
            'product_limit' => null,
            'used_product_limit' => 0,
            'remaining_product_limit' => null,
            'can_add_product' => $seller->getAttribute('activation_status') === null
                || $seller->getAttribute('activation_status') === 'active',
            'package_required' => false,
        ];
    }

    public function assertCanReserve(Seller $seller): ?SellerPackageSubscription
    {
        $activationStatus = $seller->getAttribute('activation_status');
        if ($activationStatus !== null && $activationStatus !== 'active') {
            throw new DomainException('seller_activation_is_required_before_adding_products');
        }

        return $this->activeSubscriptionQuery($seller)->first();
    }

    public function reserveForProduct(Product $product, Seller $seller): ?SellerProductEntitlement
    {
        $this->assertSellerOwnsProduct($seller, $product);
        $this->assertCanReserve($seller);

        // Keep historical entitlement rows readable, but do not create a new
        // package entitlement for ordinary product publishing.
        return null;
    }

    public function activateForPublication(Product $product, Seller $seller): ?SellerProductEntitlement
    {
        $this->assertSellerOwnsProduct($seller, $product);
        $this->assertCanReserve($seller);
        if ((int) $product->request_status !== 1) {
            throw new DomainException('product_must_be_approved_before_publication');
        }

        return null;
    }

    public function restoreAfterRejection(Product $product): void
    {
        // Product review no longer owns or restores advertising-package quota.
    }

    public function cancelForDeletion(Product $product): void
    {
        // Deleting a product does not alter package purchase history. Active ad
        // requests are handled by the promotion service/admin workflow.
    }

    public function expireDueEntitlements(?int $sellerId = null): int
    {
        $expired = 0;
        SellerProductEntitlement::query()
            ->where('status', SellerProductEntitlement::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderBy('id')
            ->chunkById(100, function ($entitlements) use (&$expired) {
                foreach ($entitlements as $entitlement) {
                    DB::transaction(function () use ($entitlement, &$expired) {
                        $locked = SellerProductEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->first();
                        if (! $locked || $locked->status !== SellerProductEntitlement::STATUS_ACTIVE
                            || ! $locked->expires_at?->isPast()) {
                            return;
                        }
                        $product = Product::query()->withoutGlobalScopes()->find($locked->product_id);
                        $this->expireEntitlement($locked, $product);
                        $expired++;
                    });
                }
            });

        if ($expired > 0) {
            cacheRemoveByType(type: 'products');
        }

        return $expired;
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

    private function expireSubscription(Seller $seller): void
    {
        $seller->packageSubscriptions()
            ->where('status', SellerPackageSubscription::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => SellerPackageSubscription::STATUS_EXPIRED]);
    }

    private function productQuotaTotal(SellerPackageSubscription $subscription): int
    {
        return max(0, $subscription->product_limit + $subscription->product_adjustment_limit);
    }

    private function restoreReservedEntitlement(SellerProductEntitlement $entitlement, string $note): void
    {
        if ($entitlement->quota_restored || $entitlement->status !== SellerProductEntitlement::STATUS_RESERVED) {
            return;
        }

        $subscription = SellerPackageSubscription::query()
            ->whereKey($entitlement->seller_package_subscription_id)
            ->lockForUpdate()
            ->first();
        if ($subscription && $subscription->used_product_limit > 0) {
            $subscription->decrement('used_product_limit');
            $subscription->refresh();
        }

        $entitlement->update([
            'status' => SellerProductEntitlement::STATUS_RESTORED,
            'quota_restored' => true,
            'quota_restored_at' => now(),
            'cancelled_at' => now(),
        ]);

        if ($subscription) {
            $this->recordQuotaTransaction(
                $subscription,
                Product::query()->withoutGlobalScopes()->findOrFail($entitlement->product_id),
                $entitlement,
                SellerPackageTransaction::TYPE_QUOTA_RESTORE,
                credit: 1,
                balanceAfter: max(0, $this->productQuotaTotal($subscription) - $subscription->used_product_limit),
                note: $note
            );
        }
    }

    private function expireEntitlement(SellerProductEntitlement $entitlement, ?Product $product): void
    {
        $entitlement->update([
            'status' => SellerProductEntitlement::STATUS_EXPIRED,
            'expired_at' => now(),
        ]);
        // Legacy listing entitlement expiry must never unpublish a product now
        // that packages are advertising-only.
    }

    private function recordQuotaTransaction(
        SellerPackageSubscription $subscription,
        Product $product,
        SellerProductEntitlement $entitlement,
        string $transactionType,
        int $credit = 0,
        int $debit = 0,
        int $balanceAfter = 0,
        string $note = ''
    ): void {
        SellerPackageTransaction::create([
            'seller_id' => $subscription->seller_id,
            'seller_package_subscription_id' => $subscription->id,
            'seller_package_id' => $subscription->seller_package_id,
            'product_id' => $product->id,
            'seller_product_entitlement_id' => $entitlement->id,
            'transaction_id' => (string) Str::uuid(),
            'transaction_type' => $transactionType,
            'quota_type' => SellerPackageTransaction::QUOTA_PRODUCT,
            'credit' => $credit,
            'debit' => $debit,
            'balance_after' => $balanceAfter,
            'paid_amount' => 0,
            'note' => $note,
        ]);
    }

    private function assertSellerOwnsProduct(Seller $seller, Product $product): void
    {
        if ($product->added_by !== 'seller' || (int) $product->user_id !== (int) $seller->id) {
            throw new DomainException('seller_product_not_found');
        }
    }
}
