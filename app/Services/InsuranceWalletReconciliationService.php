<?php

namespace App\Services;

use App\Models\CustomerInsuranceLedgerEntry;
use App\Models\InsuranceBalanceAction;
use App\Models\SellerLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InsuranceWalletReconciliationService
{
    public function summary(?int $customerId = null, ?int $sellerId = null): array
    {
        $customerLedger = CustomerInsuranceLedgerEntry::query()
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId));
        $sellerInsuranceLedger = SellerLedgerEntry::query()
            ->where('bucket', SellerLedgerService::ORDER_INSURANCE_CREDIT)
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId));

        $customerNet = (float) (clone $customerLedger)
            ->selectRaw('COALESCE(SUM(credit - debit), 0) AS balance')->value('balance');
        $sellerNet = (float) (clone $sellerInsuranceLedger)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END), 0) AS balance")
            ->value('balance');

        $legacyPurchaseUses = (clone $customerLedger)
            ->whereIn('entry_type', ['purchase_debit', 'purchase_reversal'])->count();
        $newCustomerViolations = (clone $customerLedger)
            ->whereNotIn('entry_type', [
                'hold', 'matured_credit', 'welcome_credit', 'insurance_payment_debit',
                'insurance_payment_reversal', 'admin_governance_debit', 'admin_governance_credit',
                'purchase_debit', 'purchase_reversal',
            ])->count();

        $sellerOperatingInsuranceViolations = SellerLedgerEntry::query()
            ->where('bucket', SellerLedgerService::OPERATING)
            ->where('direction', 'debit')
            ->where('event_type', 'like', '%insurance%')
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->count();

        $purchaseWalletInsuranceViolations = Schema::hasTable('wallet_transactions')
            ? DB::table('wallet_transactions')->where('transaction_type', 'like', '%insurance%')
                ->when($customerId, fn ($query) => $query->where('user_id', $customerId))->count()
            : 0;

        $actions = InsuranceBalanceAction::query()
            ->when($customerId || $sellerId, function ($query) use ($customerId, $sellerId) {
                $query->where(function ($nested) use ($customerId, $sellerId) {
                    if ($customerId) $nested->orWhere(fn ($q) => $q->where('subject_type', 'customer')->where('subject_id', $customerId));
                    if ($sellerId) $nested->orWhere(fn ($q) => $q->where('subject_type', 'seller')->where('subject_id', $sellerId));
                });
            });
        $held = (float) (clone $actions)->where('action', 'hold')->sum('amount')
            - (float) (clone $actions)->whereNotNull('parent_action_id')->whereIn('action', ['release', 'confiscate'])->sum('amount');
        $confiscated = (float) (clone $actions)->where('action', 'confiscate')->sum('amount')
            - (float) (clone $actions)->where('action', 'reverse_confiscation')->sum('amount');

        return [
            'customer_insurance_balance' => round($customerNet, 2),
            'seller_insurance_balance' => round($sellerNet, 2),
            'insurance_held_by_admin' => round(max(0, $held), 2),
            'insurance_net_confiscated' => round(max(0, $confiscated), 2),
            'legacy_customer_purchase_movements' => $legacyPurchaseUses,
            'new_customer_ledger_policy_violations' => $newCustomerViolations,
            'seller_operating_insurance_violations' => $sellerOperatingInsuranceViolations,
            'purchase_wallet_insurance_violations' => $purchaseWalletInsuranceViolations,
            'customer_balance_negative' => $customerNet < -0.000001,
            'seller_balance_negative' => $sellerNet < -0.000001,
            'is_reconciled' => $newCustomerViolations === 0
                && $purchaseWalletInsuranceViolations === 0
                && $sellerOperatingInsuranceViolations === 0
                && ! ($customerNet < -0.000001)
                && ! ($sellerNet < -0.000001),
        ];
    }
}
