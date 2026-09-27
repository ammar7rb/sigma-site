<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletTransaction;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerPurchaseWalletPolicyService
{
    private const PURCHASE_DEBITS = ['order_place', 'due_payment_for_order'];
    private const PURCHASE_CREDITS = [
        'add_fund_by_admin', 'add_fund', 'order_refund', 'loyalty_point',
        'return_order_amount_by_admin',
    ];

    public function assertTransactionAllowed(string $transactionType): void
    {
        $normalized = strtolower(trim($transactionType));
        if (str_contains($normalized, 'insurance')) {
            throw new DomainException('purchase_wallet_cannot_pay_insurance');
        }
        if (! in_array($normalized, [...self::PURCHASE_DEBITS, ...self::PURCHASE_CREDITS], true)) {
            // Preserve unrelated historical extensions, but explicitly mark
            // the policy for all core platform transactions.
            return;
        }
    }

    public function contract(): array
    {
        return [
            'wallet' => 'purchase_wallet',
            'allowed_uses' => ['product_purchase', 'purchase_refund'],
            'insurance_payment_allowed' => false,
            'withdrawable' => false,
        ];
    }

    public function creditPurchaseRefund(int $customerId, float $orderAmount, string $reference): WalletTransaction
    {
        if ($orderAmount <= 0) {
            throw new DomainException('purchase_refund_amount_must_be_positive');
        }

        return DB::transaction(function () use ($customerId, $orderAmount, $reference): WalletTransaction {
            $existing = WalletTransaction::query()
                ->where('user_id', $customerId)->where('reference', $reference)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $customer = User::query()->lockForUpdate()->findOrFail($customerId);
            $credit = (float) currencyConverter($orderAmount);
            $customer->wallet_balance = (float) $customer->wallet_balance + $credit;
            $customer->save();

            return WalletTransaction::query()->create([
                'user_id' => $customerId,
                'transaction_id' => (string) Str::uuid(),
                'reference' => $reference,
                'transaction_type' => 'order_refund',
                'payment_method' => 'purchase_wallet',
                'credit' => $credit,
                'debit' => 0,
                'admin_bonus' => 0,
                'balance' => (float) $customer->wallet_balance,
            ]);
        });
    }
}
