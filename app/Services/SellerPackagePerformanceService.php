<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SellerPackageProductMetric;
use App\Models\SellerPackageSubscription;
use App\Models\SellerProductPromotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SellerPackagePerformanceService
{
    public function recordSearchImpressions(iterable $products): void
    {
        if (! Schema::hasTable('seller_package_product_metrics')) {
            return;
        }
        $grouped = collect($products)
            ->filter(fn ($product) => $product instanceof Product
                && (bool) ($product->getAttribute('is_search_promoted') ?? $product->activeSearchPromotion))
            ->groupBy(fn (Product $product) => $product->activeSearchPromotion->seller_package_subscription_id);

        foreach ($grouped as $subscriptionId => $subscriptionProducts) {
            foreach ($subscriptionProducts->unique('id') as $product) {
                $this->increment(
                    (int) $product->user_id,
                    (int) $subscriptionId,
                    (int) $product->id,
                    'impressions'
                );
            }
        }
    }

    public function recordHomepageImpressions(iterable $products): void
    {
        if (! Schema::hasTable('seller_package_product_metrics')) {
            return;
        }
        $grouped = collect($products)
            ->filter(fn ($product) => $product instanceof Product
                && (bool) ($product->getAttribute('is_homepage_promoted') ?? $product->activeHomepagePromotion))
            ->groupBy(fn (Product $product) => $product->activeHomepagePromotion->seller_package_subscription_id);

        foreach ($grouped as $subscriptionId => $subscriptionProducts) {
            foreach ($subscriptionProducts->unique('id') as $product) {
                $this->increment((int) $product->user_id, (int) $subscriptionId, (int) $product->id, 'impressions');
            }
        }
    }

    public function recordVisit(Product $product): void
    {
        if (! Schema::hasTable('seller_package_product_metrics')) {
            return;
        }
        if ($product->added_by !== 'seller') {
            return;
        }

        $promotion = $product->activeSearchPromotion()->first();
        $subscription = $promotion?->subscription;
        if (! $subscription) {
            $subscription = SellerPackageSubscription::query()
                ->where('seller_id', $product->user_id)
                ->where('status', SellerPackageSubscription::STATUS_ACTIVE)
                ->where('payment_status', 'paid')
                ->where('starts_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('id')
                ->first();
        }
        if ($subscription) {
            $this->increment((int) $product->user_id, (int) $subscription->id, (int) $product->id, 'visits');
        }
    }

    public function report(SellerPackageSubscription $subscription): array
    {
        $from = $subscription->starts_at ?: $subscription->created_at;
        $to = $subscription->expires_at && $subscription->expires_at->lt(now()) ? $subscription->expires_at : now();
        $metrics = SellerPackageProductMetric::query()
            ->where('seller_package_subscription_id', $subscription->id)
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(visits), 0) as visits')
            ->first();

        $orders = DB::table('order_details')
            ->where('seller_id', $subscription->seller_id)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('delivery_status', ['canceled', 'failed'])
            ->selectRaw('COUNT(DISTINCT order_id) as orders_count, COALESCE(SUM(qty), 0) as units_sold, COALESCE(SUM((price - discount + tax) * qty), 0) as sales_amount')
            ->first();

        $publishedProducts = $subscription->promotions()
            ->whereIn('approval_status', [SellerProductPromotion::APPROVAL_APPROVED, SellerProductPromotion::APPROVAL_LEGACY])
            ->distinct('product_id')
            ->count('product_id');
        $impressions = (int) ($metrics->impressions ?? 0);
        $visits = (int) ($metrics->visits ?? 0);
        $ordersCount = (int) ($orders->orders_count ?? 0);

        return [
            'subscription_id' => $subscription->id,
            'package_name' => $subscription->package_name,
            'period_from' => $from,
            'period_to' => $to,
            'published_products' => $publishedProducts,
            'impressions' => $impressions,
            'visits' => $visits,
            'orders' => $ordersCount,
            'units_sold' => (int) ($orders->units_sold ?? 0),
            'sales_amount' => (float) ($orders->sales_amount ?? 0),
            'visit_rate' => $impressions > 0 ? round(($visits / $impressions) * 100, 2) : 0.0,
            'conversion_rate' => $visits > 0 ? round(($ordersCount / $visits) * 100, 2) : 0.0,
        ];
    }

    public function promotionReport(SellerProductPromotion $promotion): array
    {
        $from = $promotion->starts_at ?: $promotion->requested_at ?: $promotion->created_at;
        $to = $promotion->expires_at && $promotion->expires_at->lt(now()) ? $promotion->expires_at : now();
        $metrics = SellerPackageProductMetric::query()
            ->where('seller_package_subscription_id', $promotion->seller_package_subscription_id)
            ->where('product_id', $promotion->product_id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(visits), 0) as visits')
            ->first();
        $orders = DB::table('order_details')
            ->where('seller_id', $promotion->seller_id)
            ->where('product_id', $promotion->product_id)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('delivery_status', ['canceled', 'failed'])
            ->selectRaw('COUNT(DISTINCT order_id) as orders_count, COALESCE(SUM(qty), 0) as units_sold, COALESCE(SUM((price - discount + tax) * qty), 0) as sales_amount')
            ->first();
        $impressions = (int) ($metrics->impressions ?? 0);
        $clicks = (int) ($metrics->visits ?? 0);
        $ordersCount = (int) ($orders->orders_count ?? 0);

        return [
            'period_from' => $from,
            'period_to' => $to,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'orders' => $ordersCount,
            'units_sold' => (int) ($orders->units_sold ?? 0),
            'sales_amount' => (float) ($orders->sales_amount ?? 0),
            'click_rate' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
            'conversion_rate' => $clicks > 0 ? round(($ordersCount / $clicks) * 100, 2) : 0.0,
            'privacy_contract' => 'aggregate_only_no_customer_identity',
        ];
    }

    private function increment(int $sellerId, int $subscriptionId, int $productId, string $column): void
    {
        if ($subscriptionId <= 0) {
            return;
        }
        DB::transaction(function () use ($sellerId, $subscriptionId, $productId, $column) {
            $metric = SellerPackageProductMetric::query()->firstOrCreate([
                'seller_package_subscription_id' => $subscriptionId,
                'product_id' => $productId,
                'metric_date' => today()->toDateString(),
            ], [
                'seller_id' => $sellerId,
                'impressions' => 0,
                'visits' => 0,
            ]);
            $metric->increment($column);
        });
    }
}
