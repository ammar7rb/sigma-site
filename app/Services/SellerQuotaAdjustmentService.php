<?php

namespace App\Services;

use App\Models\Seller;
use App\Models\SellerPackageSubscription;
use App\Models\SellerPackageTransaction;
use App\Models\SellerProductPromotion;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerQuotaAdjustmentService
{
    public function adjustQuota(
        Seller $seller,
        string $quotaType,
        string $operation,
        int $amount,
        int $adminId,
        string $reason,
        string $requestToken
    ): array {
        $config = $this->quotaConfig($quotaType);

        return DB::transaction(function () use ($seller, $quotaType, $operation, $amount, $adminId, $reason, $requestToken, $config) {
            $seller = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $existingTransaction = SellerPackageTransaction::query()
                ->where('seller_id', $seller->id)
                ->where('transaction_type', SellerPackageTransaction::TYPE_QUOTA_ADJUSTMENT)
                ->where('reference', $requestToken)
                ->first();
            if ($existingTransaction) {
                return [
                    'applied' => false,
                    'transaction' => $existingTransaction,
                    'subscription' => $existingTransaction->subscription,
                ];
            }

            $subscription = $seller->packageSubscriptions()
                ->where('status', SellerPackageSubscription::STATUS_ACTIVE)
                ->where('payment_status', 'paid')
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if (! $subscription) {
                throw new DomainException('seller_has_no_active_package_to_adjust');
            }
            if ($amount <= 0 || ! in_array($operation, ['add', 'deduct'], true)) {
                throw new DomainException('invalid_seller_quota_adjustment');
            }

            $baseLimit = (int) $subscription->{$config['limit_column']};
            $usedLimit = (int) $subscription->{$config['used_column']};
            $oldAdjustment = (int) $subscription->{$config['adjustment_column']};
            $delta = $operation === 'add' ? $amount : -$amount;
            $newAdjustment = $oldAdjustment + $delta;
            $oldTotal = max(0, $baseLimit + $oldAdjustment);
            $newTotal = $baseLimit + $newAdjustment;
            if ($newTotal < $usedLimit || $newTotal < 0) {
                throw new DomainException('quota_cannot_be_reduced_below_the_already_used_amount');
            }

            $subscription->update([$config['adjustment_column'] => $newAdjustment]);
            $transaction = SellerPackageTransaction::create([
                'seller_id' => $seller->id,
                'seller_package_subscription_id' => $subscription->id,
                'seller_package_id' => $subscription->seller_package_id,
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => SellerPackageTransaction::TYPE_QUOTA_ADJUSTMENT,
                'quota_type' => $quotaType,
                'credit' => $operation === 'add' ? $amount : 0,
                'debit' => $operation === 'deduct' ? $amount : 0,
                'balance_after' => max(0, $newTotal - $usedLimit),
                'reference' => $requestToken,
                'note' => $reason,
                'created_by_admin_id' => $adminId,
                'metadata' => [
                    'operation' => $operation,
                    'base_limit' => $baseLimit,
                    'used_limit' => $usedLimit,
                    'old_adjustment' => $oldAdjustment,
                    'new_adjustment' => $newAdjustment,
                    'old_total' => $oldTotal,
                    'new_total' => $newTotal,
                ],
            ]);

            return [
                'applied' => true,
                'transaction' => $transaction,
                'subscription' => $subscription->fresh(),
            ];
        });
    }

    public function updatePromotion(
        SellerProductPromotion $promotion,
        string $action,
        int $sortOrder,
        int $adminId,
        string $reason
    ): SellerProductPromotion {
        return DB::transaction(function () use ($promotion, $action, $sortOrder, $adminId, $reason) {
            $promotion = SellerProductPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
            if (! in_array($action, ['update_order', 'cancel'], true)) {
                throw new DomainException('invalid_seller_promotion_admin_action');
            }
            if ($promotion->status !== SellerProductPromotion::STATUS_ACTIVE
                || ($promotion->expires_at && $promotion->expires_at->isPast())) {
                throw new DomainException('only_active_seller_promotions_can_be_updated');
            }

            $metadata = $promotion->metadata ?: [];
            $metadata['last_admin_update'] = [
                'action' => $action,
                'admin_id' => $adminId,
                'reason' => $reason,
                'updated_at' => now()->toDateTimeString(),
            ];
            $data = [
                'sort_order' => $sortOrder,
                'metadata' => $metadata,
            ];
            if ($action === 'update_order') {
                if ($sortOrder < 1) {
                    throw new DomainException('invalid_advertising_position');
                }
                $hasConflict = SellerProductPromotion::query()
                    ->where('id', '!=', $promotion->id)
                    ->where('status', SellerProductPromotion::STATUS_ACTIVE)
                    ->whereIn('approval_status', [SellerProductPromotion::APPROVAL_APPROVED, SellerProductPromotion::APPROVAL_LEGACY])
                    ->where('placement_scope', $promotion->placement_scope)
                    ->where('approved_position', $sortOrder)
                    ->when(
                        $promotion->placement_key === null,
                        fn ($query) => $query->whereNull('placement_key'),
                        fn ($query) => $query->where('placement_key', $promotion->placement_key)
                    )
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $promotion->starts_at ?: now()))
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<', $promotion->expires_at ?: now()->addYears(20)))
                    ->lockForUpdate()
                    ->exists();
                if ($hasConflict) {
                    throw new DomainException('advertising_placement_conflicts_with_an_existing_campaign');
                }
                $data['approved_position'] = $sortOrder;
            }
            if ($action === 'cancel') {
                $data['status'] = SellerProductPromotion::STATUS_CANCELLED;
                $data['cancelled_at'] = now();
            }
            $promotion->update($data);

            SellerPackageTransaction::create([
                'seller_id' => $promotion->seller_id,
                'seller_package_subscription_id' => $promotion->seller_package_subscription_id,
                'product_id' => $promotion->product_id,
                'seller_product_entitlement_id' => $promotion->seller_product_entitlement_id,
                'seller_product_promotion_id' => $promotion->id,
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => SellerPackageTransaction::TYPE_PROMOTION_ADMIN_UPDATE,
                'quota_type' => $promotion->promotion_type === SellerProductPromotion::TYPE_SEARCH
                    ? SellerPackageTransaction::QUOTA_SEARCH_PROMOTION
                    : SellerPackageTransaction::QUOTA_HOMEPAGE_PROMOTION,
                'balance_after' => 0,
                'reference' => 'admin-promotion-'.Str::uuid(),
                'note' => $reason,
                'created_by_admin_id' => $adminId,
                'metadata' => ['action' => $action, 'sort_order' => $sortOrder],
            ]);

            cacheRemoveByType(type: 'products');

            return $promotion->fresh();
        });
    }

    private function quotaConfig(string $quotaType): array
    {
        return match ($quotaType) {
            SellerPackageTransaction::QUOTA_SEARCH_PROMOTION => [
                'limit_column' => 'search_promotion_limit',
                'used_column' => 'used_search_promotion_limit',
                'adjustment_column' => 'search_promotion_adjustment_limit',
            ],
            SellerPackageTransaction::QUOTA_HOMEPAGE_PROMOTION => [
                'limit_column' => 'homepage_promotion_limit',
                'used_column' => 'used_homepage_promotion_limit',
                'adjustment_column' => 'homepage_promotion_adjustment_limit',
            ],
            SellerPackageTransaction::QUOTA_COUPON => [
                'limit_column' => 'coupon_limit',
                'used_column' => 'used_coupon_limit',
                'adjustment_column' => 'coupon_adjustment_limit',
            ],
            default => throw new DomainException('invalid_seller_quota_type'),
        };
    }
}
