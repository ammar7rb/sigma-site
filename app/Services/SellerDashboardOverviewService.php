<?php

namespace App\Services;

use App\Models\OrderDetail;
use App\Models\OrderTransaction;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerOrderInsurance;
use App\Models\SellerProductPromotion;
use App\Models\SellerProductContentViolation;
use App\Models\Order;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SellerDashboardOverviewService
{
    public function build(Seller $seller, int $dueLimit = 8): array
    {
        $sellerId = (int) $seller->id;
        $ledgerSummary = app(SellerLedgerService::class)->summary($sellerId);
        $products = Product::query()->withoutGlobalScopes()
            ->where('added_by', 'seller')
            ->where('user_id', $sellerId);

        $transactions = OrderTransaction::query()
            ->where('seller_is', 'seller')
            ->where('seller_id', $sellerId);

        $salesTotal = (float) (clone $transactions)->sum('seller_amount');
        $dueRows = $this->dueRows($sellerId, max(1, min($dueLimit, 20)));
        $receivedOrdersCount = app(SellerOrderVisibilityService::class)->accessible(
            Order::query()->where(['seller_is' => 'seller', 'seller_id' => $sellerId])->get()
        )->count();

        return [
            'contract_version' => 'seller_dashboard_v1',
            'generated_at' => now()->toISOString(),
            'sales' => [
                'total_amount' => $salesTotal,
                'orders_count' => (clone $transactions)->distinct('order_id')->count('order_id'),
                'received_orders_count' => $receivedOrdersCount,
                'sold_units' => $this->soldUnits($sellerId),
            ],
            'products' => [
                'total' => (clone $products)->count(),
                'active' => (clone $products)->where('status', 1)->where('request_status', 1)->count(),
                'under_review' => (clone $products)->where('request_status', 0)->count(),
                'suspended' => (clone $products)->where('request_status', 1)->where('status', 0)->count(),
                'rejected' => (clone $products)->where('request_status', 2)->count(),
            ],
            'advertisements' => $this->promotionCounts($sellerId),
            'balances' => [
                'available' => (float) ($ledgerSummary[SellerLedgerService::AVAILABLE] ?? 0),
                'pending_from_platform' => (float) ($ledgerSummary[SellerLedgerService::PENDING] ?? 0),
                'operating' => (float) ($ledgerSummary[SellerLedgerService::OPERATING] ?? 0),
                'pending_withdrawal' => (float) ($ledgerSummary[SellerLedgerService::PENDING_WITHDRAW] ?? 0),
                'order_insurance_credit' => $this->insuranceCreditBalance($sellerId),
                'shipping_due_total' => $this->shippingDueTotal($sellerId),
                'currency' => \App\Models\Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?? 'USD',
                'source' => 'server_financial_records',
            ],
            'issues' => [
                'open_product_issues' => (clone $products)
                    ->where(function ($query) {
                        $query->where('request_status', 2)
                            ->orWhere(function ($query) {
                                $query->where('request_status', 1)->where('status', 0);
                            });
                    })->count(),
                'stock_out_products' => (clone $products)
                    ->where('product_type', 'physical')->where('current_stock', '<=', 0)->count(),
            ],
            'insurance_wallet' => $this->orderInsuranceWallet($sellerId),
            'next_due_at' => $this->nextDueAt($sellerId),
            'due_rows' => $dueRows,
            'quick_actions' => [
                'add_product' => '/vendor/products/add',
                'advertising_packages' => '/vendor/packages',
                'fund_operating_balance' => '/vendor/dashboard#vendor-wallet',
            ],
        ];
    }

    private function soldUnits(int $sellerId): int
    {
        return (int) OrderDetail::query()
            ->where('seller_id', $sellerId)
            ->where('delivery_status', 'delivered')
            ->sum('qty');
    }

    private function promotionCounts(int $sellerId): array
    {
        $defaults = ['active' => 0, 'pending' => 0, 'stopped' => 0, 'violating' => 0];
        if (! Schema::hasTable('seller_product_promotions')) {
            return $defaults;
        }

        try {
            $counts = SellerProductPromotion::query()
                ->where('seller_id', $sellerId)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $violating = 0;
            if (Schema::hasTable('seller_product_content_violations')) {
                $violating = (int) SellerProductContentViolation::query()->where('seller_id', $sellerId)->count();
            }

            return [
                'active' => (int) ($counts[SellerProductPromotion::STATUS_ACTIVE] ?? 0),
                'pending' => (int) ($counts[SellerProductPromotion::STATUS_RESERVED] ?? 0),
                'stopped' => (int) (($counts[SellerProductPromotion::STATUS_CANCELLED] ?? 0) + ($counts[SellerProductPromotion::STATUS_EXPIRED] ?? 0)),
                'violating' => $violating,
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    private function nextDueAt(int $sellerId): ?string
    {
        if (! Schema::hasColumn('orders', 'shipping_settlement_due_at')) {
            return null;
        }

        $value = \App\Models\Order::query()
            ->where('seller_is', 'seller')
            ->where('seller_id', $sellerId)
            ->whereNotNull('shipping_settlement_due_at')
            ->where('shipping_settlement_due_at', '>=', now())
            ->min('shipping_settlement_due_at');

        return $value ? \Carbon\Carbon::parse($value)->toISOString() : null;
    }

    public function shippingDueTotal(int $sellerId): float
    {
        if (! Schema::hasColumn('orders', 'shipping_responsibility')) {
            return 0.0;
        }

        $amountColumn = Schema::hasColumn('orders', 'shipping_seller_entitlement')
            ? 'shipping_seller_entitlement'
            : (Schema::hasColumn('orders', 'seller_shipping_allocation')
                ? 'seller_shipping_allocation'
                : null);

        if ($amountColumn === null) {
            return 0.0;
        }

        return (float) \App\Models\Order::query()
            ->where('seller_is', 'seller')
            ->where('seller_id', $sellerId)
            ->where('shipping_responsibility', 'sellerwise_shipping')
            ->sum($amountColumn);
    }

    private function dueRows(int $sellerId, int $limit): array
    {
        $transactionQuery = OrderTransaction::query()
            ->with(Schema::hasTable('seller_order_insurances') ? 'order.sellerOrderInsurance' : 'order');

        return $transactionQuery
            ->where('seller_is', 'seller')
            ->where('seller_id', $sellerId)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(function (OrderTransaction $transaction): array {
                $order = $transaction->order;
                $sellerInsurance = Schema::hasTable('seller_order_insurances') ? $order?->sellerOrderInsurance : null;
                $sellerShips = ($order?->shipping_responsibility ?? null) === 'sellerwise_shipping';
                $salesDueAt = $order?->sales_settlement_due_at;

                return [
                    'order_id' => (int) $transaction->order_id,
                    'order_reference' => '#' . str_pad((string) $transaction->order_id, 3, '0', STR_PAD_LEFT),
                    'order_last_three' => substr(str_pad((string) $transaction->order_id, 3, '0', STR_PAD_LEFT), -3),
                    'sales_amount' => (float) $transaction->seller_amount,
                    'shipping_amount' => $sellerShips ? (float) ($order?->shipping_seller_entitlement ?? $order?->seller_shipping_allocation ?? $transaction->delivery_charge ?? 0) : 0.0,
                    'shipping_responsible' => $sellerShips,
                    'insurance_amount' => (float) ($sellerInsurance?->amount ?? 0),
                    'insurance_status' => $sellerInsurance?->status,
                    'insurance_paid_at' => $sellerInsurance?->paid_at?->toISOString(),
                    'settlement_status' => $transaction->status ?: 'pending',
                    'sales_due_at' => $salesDueAt ? \Carbon\Carbon::parse($salesDueAt)->toISOString() : null,
                    'shipping_due_at' => $order?->shipping_settlement_due_at ? \Carbon\Carbon::parse($order->shipping_settlement_due_at)->toISOString() : null,
                    'insurance_reuse_due_at' => $sellerInsurance?->reusable_at?->toISOString(),
                ];
            })->all();
    }

    private function insuranceCreditBalance(int $sellerId): float
    {
        if (! Schema::hasTable('seller_ledger_entries')) {
            return 0.0;
        }

        try {
            return (float) app(SellerLedgerService::class)->summary($sellerId)[SellerLedgerService::ORDER_INSURANCE_CREDIT];
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * This is deliberately a read-only summary of the order-insurance ledger.
     * It must never be merged with the withdrawable or operating balances.
     */
    public function orderInsuranceWallet(int $sellerId): array
    {
        $summary = [
            'available_credit' => $this->insuranceCreditBalance($sellerId),
            'pending_amount' => 0.0,
            'pending_count' => 0,
            'under_review_amount' => 0.0,
            'under_review_count' => 0,
            'locked_amount' => 0.0,
            'locked_count' => 0,
            'confiscated_amount' => 0.0,
            'next_reusable_at' => null,
        ];

        if (! Schema::hasTable('seller_order_insurances')) {
            return $summary;
        }

        try {
            $base = SellerOrderInsurance::query()->where('seller_id', $sellerId);
            $pending = (clone $base)->where('status', SellerOrderInsurance::STATUS_PENDING_PAYMENT);
            $review = (clone $base)->where('status', SellerOrderInsurance::STATUS_PENDING_REVIEW);
            $locked = (clone $base)->where('status', SellerOrderInsurance::STATUS_PAID)
                ->whereNull('reusable_released_at');

            $summary['pending_amount'] = (float) (clone $pending)->sum('amount');
            $summary['pending_count'] = (int) (clone $pending)->count();
            $summary['under_review_amount'] = (float) (clone $review)->sum('amount');
            $summary['under_review_count'] = (int) (clone $review)->count();
            $summary['locked_amount'] = (float) (clone $locked)->sum('amount');
            $summary['locked_count'] = (int) (clone $locked)->count();
            $summary['next_reusable_at'] = (clone $locked)->whereNotNull('reusable_at')->min('reusable_at');

            if (Schema::hasColumn('seller_order_insurances', 'confiscated_amount')) {
                $summary['confiscated_amount'] = (float) (clone $base)->sum('confiscated_amount');
            }
        } catch (Throwable) {
            // The dashboard must remain available on installations that are still
            // applying the financial migrations.
        }

        return $summary;
    }
}
