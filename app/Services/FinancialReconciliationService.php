<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SellerLedgerEntry;
use App\Models\SellerSettlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialReconciliationService
{
    public function summary(?int $sellerId = null, ?string $from = null, ?string $to = null): array
    {
        $orders = Order::query()
            ->when($sellerId, fn (Builder $q) => $q->where('seller_id', $sellerId)->where('seller_is', 'seller'))
            ->when($from, fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('created_at', '<=', $to));
        $paid = (clone $orders)->where('payment_status', 'paid');

        $ledger = SellerLedgerEntry::query()
            ->when($sellerId, fn (Builder $q) => $q->where('seller_id', $sellerId))
            ->when($from, fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('created_at', '<=', $to));

        $settlements = SellerSettlement::query()
            ->when($sellerId, fn (Builder $q) => $q->where('seller_id', $sellerId))
            ->when($from, fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('created_at', '<=', $to));

        return [
            'sales_revenue' => (float) (clone $paid)->sum('order_amount'),
            'admin_commission' => (float) (clone $paid)->sum('admin_commission'),
            'shipping_customer_revenue' => (float) (clone $orders)->sum('shipping_customer_cost'),
            'shipping_seller_cost' => (float) (clone $orders)->sum('shipping_seller_cost'),
            'shipping_seller_entitlement' => (float) (clone $orders)->sum('shipping_seller_entitlement'),
            'seller_package_revenue' => $this->tableSum('seller_package_transactions', 'paid_amount', $sellerId, $from, $to),
            'customer_insurance_received' => $this->insuranceSum('order_insurances', null, $from, $to),
            'seller_insurance_received' => $this->insuranceSum('seller_order_insurances', $sellerId, $from, $to),
            'refunds' => $this->refundSum($sellerId, $from, $to),
            'settlements_held' => (float) (clone $settlements)->whereIn('status', ['held', 'disputed'])->sum('amount'),
            'settlements_released' => (float) (clone $settlements)->where('status', 'released')->sum('amount'),
            'settlements_reversed' => (float) (clone $settlements)->where('status', 'reversed')->sum('amount'),
            'ledger_net' => (float) (clone $ledger)->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END), 0) AS net")->value('net'),
            'missing_references' => (clone $ledger)->where(function (Builder $q) {
                $q->whereNull('reference_code')->orWhere('reference_code', '');
            })->whereNotIn('event_type', ['legacy_opening_balance', 'legacy_wallet_sync'])->count(),
            'duplicate_idempotency_keys' => (clone $ledger)->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->count(),
            'insurance_wallets' => Schema::hasTable('insurance_balance_actions')
                && Schema::hasTable('customer_insurance_ledger_entries')
                && Schema::hasTable('seller_ledger_entries')
                ? app(InsuranceWalletReconciliationService::class)->summary(sellerId: $sellerId)
                : null,
        ];
    }

    private function tableSum(string $table, string $column, ?int $sellerId, ?string $from, ?string $to): float
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) return 0;
        $query = DB::table($table)->when($sellerId && Schema::hasColumn($table, 'seller_id'), fn ($q) => $q->where('seller_id', $sellerId));
        if ($from) $query->whereDate('created_at', '>=', $from);
        if ($to) $query->whereDate('created_at', '<=', $to);
        return (float) $query->sum($column);
    }

    private function insuranceSum(string $table, ?int $sellerId, ?string $from, ?string $to): float
    {
        if (! Schema::hasTable($table)) return 0;
        $query = DB::table($table)->where('payment_status', 'paid');
        if ($sellerId && Schema::hasColumn($table, 'seller_id')) $query->where('seller_id', $sellerId);
        if ($from) $query->whereDate('created_at', '>=', $from);
        if ($to) $query->whereDate('created_at', '<=', $to);
        return (float) $query->sum('amount');
    }

    private function refundSum(?int $sellerId, ?string $from, ?string $to): float
    {
        if (! Schema::hasTable('refund_requests')) return 0;
        $query = DB::table('refund_requests')->whereIn('status', ['approved', 'refunded']);
        if ($sellerId) {
            if (Schema::hasColumn('refund_requests', 'seller_id')) {
                $query->where('seller_id', $sellerId);
            } else {
                $query->whereIn('order_id', Order::query()->where('seller_id', $sellerId)->where('seller_is', 'seller')->select('id'));
            }
        }
        if ($from) $query->whereDate('created_at', '>=', $from);
        if ($to) $query->whereDate('created_at', '<=', $to);
        return (float) $query->sum('amount');
    }
}
