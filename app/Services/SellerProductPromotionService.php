<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerPackageSubscription;
use App\Models\SellerPackageTransaction;
use App\Models\SellerProductEntitlement;
use App\Models\SellerProductPromotion;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SellerProductPromotionService
{
    public function getSearchSummary(Seller $seller): array
    {
        $summary = $this->getPromotionSummary($seller, SellerProductPromotion::TYPE_SEARCH);

        return [
            'insurance_satisfied' => $summary['insurance_satisfied'],
            'active_subscription' => $summary['active_subscription'],
            'search_promotion_limit' => $summary['promotion_limit'],
            'used_search_promotion_limit' => $summary['used_promotion_limit'],
            'remaining_search_promotion_limit' => $summary['remaining_promotion_limit'],
            'search_promotion_duration_days' => $summary['promotion_duration_days'],
            'can_promote' => $summary['can_promote'],
        ];
    }

    public function getHomepageSummary(Seller $seller): array
    {
        $summary = $this->getPromotionSummary($seller, SellerProductPromotion::TYPE_HOMEPAGE);

        return [
            'insurance_satisfied' => $summary['insurance_satisfied'],
            'active_subscription' => $summary['active_subscription'],
            'homepage_promotion_limit' => $summary['promotion_limit'],
            'used_homepage_promotion_limit' => $summary['used_promotion_limit'],
            'remaining_homepage_promotion_limit' => $summary['remaining_promotion_limit'],
            'homepage_promotion_duration_days' => $summary['promotion_duration_days'],
            'can_promote' => $summary['can_promote'],
        ];
    }

    public function activateSearchPromotion(Product $product, Seller $seller): SellerProductPromotion
    {
        return $this->requestPromotion($product, $seller, SellerProductPromotion::TYPE_SEARCH);
    }

    public function activateHomepagePromotion(Product $product, Seller $seller): SellerProductPromotion
    {
        return $this->requestPromotion($product, $seller, SellerProductPromotion::TYPE_HOMEPAGE);
    }

    public function requestPromotion(
        Product $product,
        Seller $seller,
        string $promotionType,
        ?string $placementKey = null,
        ?int $requestedPosition = null,
    ): SellerProductPromotion
    {
        $config = $this->promotionConfig($promotionType);

        return DB::transaction(function () use ($product, $seller, $promotionType, $config, $placementKey, $requestedPosition) {
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $product = Product::query()->withoutGlobalScopes()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertSellerOwnsProduct($seller, $product);

            if ((int) $product->request_status !== 1 || (int) $product->status !== 1) {
                throw new DomainException($config['inactive_product_error']);
            }

            $existing = $product->sellerProductPromotions()
                ->where('promotion_type', $promotionType)
                ->where(function ($query) {
                    $query->where('approval_status', SellerProductPromotion::APPROVAL_PENDING)
                        ->orWhere(function ($query) {
                            $query->where('approval_status', SellerProductPromotion::APPROVAL_APPROVED)
                                ->where('status', SellerProductPromotion::STATUS_ACTIVE);
                        });
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($existing && ($existing->approval_status === SellerProductPromotion::APPROVAL_PENDING
                || ! $existing->expires_at || $existing->expires_at->isFuture())) {
                return $existing;
            }
            if ($existing) {
                $existing->update([
                    'status' => SellerProductPromotion::STATUS_EXPIRED,
                    'expired_at' => now(),
                ]);
            }

            $subscription = $this->activeSubscriptionQuery($seller)->lockForUpdate()->first();
            if (! $subscription) {
                throw new DomainException('active_seller_package_is_required_before_promoting_products');
            }
            $durationDays = (int) $subscription->{$config['duration_column']};
            if ($durationDays <= 0) {
                throw new DomainException($config['duration_error']);
            }

            $total = $this->quotaTotal($subscription, $config);
            if ($subscription->{$config['used_column']} >= $total) {
                throw new DomainException($config['limit_error']);
            }

            $entitlement = $this->getPublicationEntitlement($product);
            $defaultScope = $promotionType === SellerProductPromotion::TYPE_SEARCH
                ? 'search_results'
                : 'homepage_featured';
            $promotionData = [
                'seller_id' => $seller->id,
                'product_id' => $product->id,
                'seller_package_subscription_id' => $subscription->id,
                'seller_product_entitlement_id' => $entitlement?->id,
                'promotion_type' => $promotionType,
                'duration_days' => $durationDays,
                'status' => SellerProductPromotion::STATUS_RESERVED,
                'approval_status' => SellerProductPromotion::APPROVAL_PENDING,
                'requested_at' => now(),
                'placement_scope' => $defaultScope,
                'placement_key' => $placementKey ? trim($placementKey) : null,
                'requested_position' => $requestedPosition ? max(1, $requestedPosition) : null,
                'metadata' => [
                    'requested_from' => $config['activated_from'],
                    'package_search_priority_snapshot' => (int) ($subscription->search_priority ?: 100),
                    'quota_consumed' => false,
                ],
            ];
            if (Schema::hasColumn('seller_product_promotions', 'search_priority')) {
                $promotionData['search_priority'] = (int) ($subscription->search_priority ?: 100);
            }
            return SellerProductPromotion::create($promotionData);
        });
    }

    public function reviewPromotion(
        SellerProductPromotion $promotion,
        string $decision,
        int $adminId,
        string $reviewNote,
        ?string $placementScope = null,
        ?string $placementKey = null,
        ?int $position = null,
        Carbon|string|null $startsAt = null,
        Carbon|string|null $expiresAt = null,
        ?string $badgeLabel = null,
    ): SellerProductPromotion {
        return DB::transaction(function () use ($promotion, $decision, $adminId, $reviewNote, $placementScope, $placementKey, $position, $startsAt, $expiresAt, $badgeLabel) {
            $promotion = SellerProductPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
            if ($promotion->approval_status !== SellerProductPromotion::APPROVAL_PENDING
                || $promotion->status !== SellerProductPromotion::STATUS_RESERVED) {
                throw new DomainException('promotion_request_is_not_pending_admin_review');
            }
            if (! in_array($decision, ['approve', 'reject'], true) || trim($reviewNote) === '') {
                throw new DomainException('promotion_review_decision_and_reason_are_required');
            }

            if ($decision === 'reject') {
                $promotion->update([
                    'approval_status' => SellerProductPromotion::APPROVAL_REJECTED,
                    'status' => SellerProductPromotion::STATUS_CANCELLED,
                    'reviewed_by_admin_id' => $adminId,
                    'reviewed_at' => now(),
                    'review_note' => $reviewNote,
                    'cancelled_at' => now(),
                ]);

                return $promotion->fresh();
            }

            $config = $this->promotionConfig($promotion->promotion_type);
            $subscription = SellerPackageSubscription::query()
                ->whereKey($promotion->seller_package_subscription_id)
                ->where('status', SellerPackageSubscription::STATUS_ACTIVE)
                ->where('payment_status', 'paid')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->lockForUpdate()
                ->first();
            if (! $subscription) {
                throw new DomainException('advertising_package_is_not_active_for_this_request');
            }
            $total = $this->quotaTotal($subscription, $config);
            if ((int) $subscription->{$config['used_column']} >= $total) {
                throw new DomainException($config['limit_error']);
            }

            $scope = $placementScope ?: $promotion->placement_scope;
            $allowedScopes = $promotion->promotion_type === SellerProductPromotion::TYPE_SEARCH
                ? ['search_results', 'category_search', 'keyword_search']
                : ['homepage_featured', 'homepage_bestseller', 'homepage_latest', 'homepage_popular'];
            if (! in_array($scope, $allowedScopes, true)) {
                throw new DomainException('invalid_advertising_placement_scope');
            }
            $key = trim((string) ($placementKey ?? $promotion->placement_key));
            $key = $key !== '' ? mb_strtolower($key) : null;
            if (in_array($scope, ['category_search', 'keyword_search'], true) && $key === null) {
                throw new DomainException('category_or_keyword_is_required_for_the_selected_advertising_scope');
            }
            $approvedPosition = max(1, (int) ($position ?: $promotion->requested_position ?: $subscription->search_priority ?: 100));
            $start = $startsAt ? Carbon::parse($startsAt) : now();
            $maximumEnd = $start->copy()->addDays((int) $promotion->duration_days);
            $end = $expiresAt ? Carbon::parse($expiresAt) : $maximumEnd;
            if ($end->lte($start) || $end->gt($maximumEnd)) {
                throw new DomainException('promotion_period_exceeds_advertising_package_benefit');
            }

            $conflict = SellerProductPromotion::query()
                ->where('id', '!=', $promotion->id)
                ->whereIn('approval_status', [SellerProductPromotion::APPROVAL_APPROVED, SellerProductPromotion::APPROVAL_LEGACY])
                ->where('status', SellerProductPromotion::STATUS_ACTIVE)
                ->where('placement_scope', $scope)
                ->where('approved_position', $approvedPosition)
                ->when($key === null, fn ($query) => $query->whereNull('placement_key'), fn ($query) => $query->where('placement_key', $key))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $start))
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<', $end))
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                throw new DomainException('advertising_placement_conflicts_with_an_existing_campaign');
            }

            $subscription->increment($config['used_column']);
            $subscription->refresh();
            $metadata = $promotion->metadata ?: [];
            $metadata['quota_consumed'] = true;
            $metadata['badge_label'] = trim((string) $badgeLabel) ?: 'Featured_Ad';
            $metadata['approved_snapshot'] = [
                'scope' => $scope, 'key' => $key, 'position' => $approvedPosition,
                'starts_at' => $start->toDateTimeString(), 'expires_at' => $end->toDateTimeString(),
                'admin_id' => $adminId, 'badge_label' => $metadata['badge_label'],
            ];
            $promotion->update([
                'approval_status' => SellerProductPromotion::APPROVAL_APPROVED,
                'status' => SellerProductPromotion::STATUS_ACTIVE,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
                'placement_scope' => $scope,
                'placement_key' => $key,
                'approved_position' => $approvedPosition,
                'sort_order' => $approvedPosition,
                'starts_at' => $start,
                'expires_at' => $end,
                'activated_at' => $start,
                'metadata' => $metadata,
            ]);

            SellerPackageTransaction::create([
                'seller_id' => $promotion->seller_id,
                'seller_package_subscription_id' => $subscription->id,
                'seller_package_id' => $subscription->seller_package_id,
                'product_id' => $promotion->product_id,
                'seller_product_entitlement_id' => $promotion->seller_product_entitlement_id,
                'seller_product_promotion_id' => $promotion->id,
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => SellerPackageTransaction::TYPE_QUOTA_USAGE,
                'quota_type' => $config['quota_type'],
                'debit' => 1,
                'balance_after' => max(0, $total - (int) $subscription->{$config['used_column']}),
                'paid_amount' => 0,
                'note' => $config['transaction_note'].' Approved by admin.',
                'created_by_admin_id' => $adminId,
                'metadata' => ['placement_scope' => $scope, 'placement_key' => $key, 'position' => $approvedPosition],
            ]);

            cacheRemoveByType(type: 'products');

            return $promotion->fresh();
        });
    }

    private function getPromotionSummary(Seller $seller, string $promotionType): array
    {
        $config = $this->promotionConfig($promotionType);
        $this->expireDuePromotions($seller->id);
        $subscription = $this->activeSubscriptionQuery($seller)->first();
        $total = $subscription ? $this->quotaTotal($subscription, $config) : 0;
        $used = $subscription ? (int) $subscription->{$config['used_column']} : 0;
        $duration = $subscription ? (int) $subscription->{$config['duration_column']} : 0;

        return [
            // Seller insurance is assessed per received order, not as a
            // prerequisite for using an already purchased package benefit.
            'insurance_satisfied' => true,
            'active_subscription' => $subscription,
            'promotion_limit' => $total,
            'used_promotion_limit' => $used,
            'remaining_promotion_limit' => max(0, $total - $used),
            'promotion_duration_days' => $duration,
            'can_promote' => $subscription && $duration > 0 && $used < $total,
        ];
    }

    public function expireDuePromotions(?int $sellerId = null): int
    {
        $expired = SellerProductPromotion::query()
            ->where('status', SellerProductPromotion::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->update([
                'status' => SellerProductPromotion::STATUS_EXPIRED,
                'expired_at' => now(),
            ]);

        if ($expired > 0) {
            cacheRemoveByType(type: 'products');
        }

        return $expired;
    }

    private function getPublicationEntitlement(Product $product): ?SellerProductEntitlement
    {
        // Product publication is no longer tied to an advertising package.
        // Keep the historical entitlement reference only when a still-active
        // legacy record exists; its absence must never block an ad request.
        return $product->sellerProductEntitlements()
            ->where('status', SellerProductEntitlement::STATUS_ACTIVE)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();
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

    private function quotaTotal(SellerPackageSubscription $subscription, array $config): int
    {
        return max(0, $subscription->{$config['limit_column']} + $subscription->{$config['adjustment_column']});
    }

    private function promotionConfig(string $promotionType): array
    {
        return match ($promotionType) {
            SellerProductPromotion::TYPE_SEARCH => [
                'limit_column' => 'search_promotion_limit',
                'used_column' => 'used_search_promotion_limit',
                'adjustment_column' => 'search_promotion_adjustment_limit',
                'duration_column' => 'search_promotion_duration_days',
                'quota_type' => SellerPackageTransaction::QUOTA_SEARCH_PROMOTION,
                'inactive_product_error' => 'only_active_approved_products_can_be_promoted_in_search',
                'duration_error' => 'seller_package_search_promotion_duration_is_not_configured',
                'limit_error' => 'seller_package_search_promotion_limit_has_been_reached',
                'listing_duration_error' => 'product_listing_duration_must_cover_search_promotion_duration',
                'activated_from' => 'seller_search_promotion',
                'transaction_note' => 'Seller search promotion quota used.',
            ],
            SellerProductPromotion::TYPE_HOMEPAGE => [
                'limit_column' => 'homepage_promotion_limit',
                'used_column' => 'used_homepage_promotion_limit',
                'adjustment_column' => 'homepage_promotion_adjustment_limit',
                'duration_column' => 'homepage_promotion_duration_days',
                'quota_type' => SellerPackageTransaction::QUOTA_HOMEPAGE_PROMOTION,
                'inactive_product_error' => 'only_active_approved_products_can_be_promoted_on_homepage',
                'duration_error' => 'seller_package_homepage_promotion_duration_is_not_configured',
                'limit_error' => 'seller_package_homepage_promotion_limit_has_been_reached',
                'listing_duration_error' => 'product_listing_duration_must_cover_homepage_promotion_duration',
                'activated_from' => 'seller_homepage_promotion',
                'transaction_note' => 'Seller homepage promotion quota used.',
            ],
            default => throw new DomainException('invalid_seller_product_promotion_type'),
        };
    }

    private function assertSellerOwnsProduct(Seller $seller, Product $product): void
    {
        if ($product->added_by !== 'seller' || (int) $product->user_id !== (int) $seller->id) {
            throw new DomainException('seller_product_not_found');
        }
    }
}
