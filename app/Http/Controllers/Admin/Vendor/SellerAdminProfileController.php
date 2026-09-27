<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Models\SellerBalanceDeposit;
use App\Models\SellerInsurance;
use App\Models\SellerLedgerEntry;
use App\Models\SellerPackageSubscription;
use App\Models\SellerSettlement;
use App\Models\SellerStockViolation;
use App\Models\WithdrawRequest;
use App\Services\SellerLedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;

class SellerAdminProfileController extends Controller
{
    /**
     * A read-only operational profile.  It deliberately consolidates the
     * existing seller records instead of copying financial data into a new
     * table, so the profile always reflects the source of truth.
     */
    public function show(int $id): View|RedirectResponse
    {
        $seller = Seller::query()
            ->with(['shop', 'wallet', 'activePackageSubscription', 'activeInsurance'])
            ->withCount([
                'product',
                'orders as orders_count' => fn ($query) => $query->where('seller_is', 'seller'),
                'orders as delivered_orders_count' => fn ($query) => $query
                    ->where('seller_is', 'seller')
                    ->where('order_status', 'delivered'),
            ])
            ->find($id);

        if (! $seller) {
            return redirect()->route('admin.vendors.vendor-list');
        }

        $grossSales = (float) $seller->orders()
            ->where('seller_is', 'seller')
            ->where('order_status', 'delivered')
            ->sum('order_amount');

        $ratingTotals = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $products = $seller->product()->with('reviews')->get();
        foreach ($products as $product) {
            foreach ($product->reviews as $review) {
                $rating = (int) $review->rating;
                if (isset($ratingTotals[$rating])) {
                    $ratingTotals[$rating]++;
                }
            }
        }
        $ratingCount = array_sum($ratingTotals);
        $totalRating = array_sum(array_map(fn ($rating, $count) => $rating * $count, array_keys($ratingTotals), $ratingTotals));

        $ledgerSummary = $this->ledgerSummary($seller);
        $packages = $this->recentRecords('seller_package_subscriptions', fn () => SellerPackageSubscription::query()
            ->where('seller_id', $seller->id)->latest()->limit(10)->get());
        $insurances = $this->recentRecords('seller_insurances', fn () => SellerInsurance::query()
            ->where('seller_id', $seller->id)->latest()->limit(10)->get());
        $deposits = $this->recentRecords('seller_balance_deposits', fn () => SellerBalanceDeposit::query()
            ->where('seller_id', $seller->id)->latest()->limit(10)->get());
        $settlements = $this->recentRecords('seller_settlements', fn () => SellerSettlement::query()
            ->with('order')->where('seller_id', $seller->id)->latest()->limit(10)->get());
        $withdrawals = $this->recentRecords('withdraw_requests', fn () => WithdrawRequest::query()
            ->with('vendorWithdrawMethodInfo')->where('seller_id', $seller->id)->latest()->limit(10)->get());
        $violations = $this->recentRecords('seller_stock_violations', fn () => SellerStockViolation::query()
            ->with('product')->where('seller_id', $seller->id)->latest('decided_at')->limit(10)->get());
        $ledgerEntries = $this->recentRecords('seller_ledger_entries', fn () => SellerLedgerEntry::query()
            ->where('seller_id', $seller->id)->latest()->limit(12)->get());

        return view('admin-views.vendor.profile.show', compact(
            'seller', 'grossSales', 'ledgerSummary', 'packages', 'insurances',
            'deposits', 'settlements', 'withdrawals', 'violations', 'ledgerEntries',
            'ratingTotals', 'ratingCount', 'totalRating',
        ));
    }

    private function ledgerSummary(Seller $seller): array
    {
        $summary = array_fill_keys([
            SellerLedgerService::SALES_TOTAL,
            SellerLedgerService::PENDING,
            SellerLedgerService::AVAILABLE,
            SellerLedgerService::OPERATING,
            SellerLedgerService::PENDING_WITHDRAW,
            SellerLedgerService::WITHDRAWN,
        ], 0.0);

        if (! Schema::hasTable('seller_ledger_entries')) {
            return $this->legacyWalletSummary($seller, $summary);
        }

        $rows = SellerLedgerEntry::query()
            ->select('bucket')
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS balance")
            ->where('seller_id', $seller->id)
            ->groupBy('bucket')
            ->pluck('balance', 'bucket');

        // Older sellers can have a wallet created before the immutable ledger.
        // A profile view must not write an opening movement merely to display it.
        if ($rows->isEmpty()) {
            return $this->legacyWalletSummary($seller, $summary);
        }

        foreach ($summary as $bucket => $amount) {
            $summary[$bucket] = (float) ($rows[$bucket] ?? 0);
        }

        return $summary;
    }

    private function legacyWalletSummary(Seller $seller, array $summary): array
    {
        $summary[SellerLedgerService::SALES_TOTAL] = (float) ($seller->wallet?->total_earning ?? 0);
        $summary[SellerLedgerService::AVAILABLE] = (float) ($seller->wallet?->total_earning ?? 0);
        $summary[SellerLedgerService::PENDING_WITHDRAW] = (float) ($seller->wallet?->pending_withdraw ?? 0);
        $summary[SellerLedgerService::WITHDRAWN] = (float) ($seller->wallet?->withdrawn ?? 0);

        return $summary;
    }

    private function recentRecords(string $table, callable $query)
    {
        return Schema::hasTable($table) ? $query() : collect();
    }
}
