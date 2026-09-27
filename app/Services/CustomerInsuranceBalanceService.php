<?php

namespace App\Services;

use App\Models\CustomerInsuranceLedgerEntry;
use App\Models\OrderInsurance;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Separate, non-withdrawable balance created only by matured order insurance.
 * It never mutates users.wallet_balance, which remains the platform wallet.
 */
class CustomerInsuranceBalanceService
{
    public function summary(int $customerId): array
    {
        app(CustomerInsuranceIncentiveService::class)->grantEligibleWelcomeCredits($customerId);
        $available = (float) CustomerInsuranceLedgerEntry::query()
            ->where('customer_id', $customerId)
            ->selectRaw('COALESCE(SUM(credit - debit), 0) as balance')
            ->value('balance');

        $held = (float) OrderInsurance::query()
            ->where('customer_id', $customerId)
            ->where('status', 'held')
            ->sum('amount');

        $nextMaturity = OrderInsurance::query()
            ->where('customer_id', $customerId)
            ->where('status', 'held')
            ->whereNotNull('matures_at')
            ->orderBy('matures_at')
            ->value('matures_at');

        // Older refunds use the v3 balance table; deposits and matured order
        // insurance use the immutable ledger. Both remain insurance-only.
        $refundBalance = \Illuminate\Support\Facades\Schema::hasTable('customer_insurance_balances')
            ? DB::table('customer_insurance_balances')->where('customer_id', $customerId)->first() : null;
        return [
            'available_balance' => round(max(0, $available) + (float) ($refundBalance->available_amount ?? 0), 2),
            'held_balance' => round(max(0, $held) + (float) ($refundBalance->held_amount ?? 0), 2),
            'next_maturity_at' => $nextMaturity,
            'withdrawable' => false,
            'allowed_uses' => ['insurance_payment'],
        ];
    }

    public function recordHold(OrderInsurance $insurance): void
    {
        if ($insurance->payment_status !== 'paid') {
            return;
        }

        CustomerInsuranceLedgerEntry::query()->firstOrCreate(
            ['reference' => 'order-insurance-hold-' . $insurance->id],
            [
                'customer_id' => $insurance->customer_id,
                'order_insurance_id' => $insurance->id,
                'order_id' => $insurance->order_id,
                'entry_type' => 'hold',
                'credit' => 0,
                'debit' => 0,
                'metadata' => ['matures_at' => optional($insurance->matures_at)->toIso8601String()],
            ]
        );
    }

    public function releaseDue(?int $limit = null): int
    {
        $query = OrderInsurance::query()
            ->where('status', 'held')
            ->where('payment_status', 'paid')
            ->whereNotNull('matures_at')
            ->where('matures_at', '<=', now())
            ->orderBy('id');

        if ($limit) {
            $query->limit($limit);
        }

        $count = 0;
        foreach ($query->get() as $candidate) {
            $released = DB::transaction(function () use ($candidate): bool {
                $insurance = OrderInsurance::query()->lockForUpdate()->find($candidate->id);
                if (!$insurance || $insurance->status !== 'held' || !$insurance->matures_at || $insurance->matures_at->isFuture()) {
                    return false;
                }

                CustomerInsuranceLedgerEntry::query()->firstOrCreate(
                    ['reference' => 'order-insurance-mature-' . $insurance->id],
                    [
                        'customer_id' => $insurance->customer_id,
                        'order_insurance_id' => $insurance->id,
                        'order_id' => $insurance->order_id,
                        'entry_type' => 'matured_credit',
                        'credit' => $insurance->amount,
                        'debit' => 0,
                        'metadata' => [
                            'maturity_days' => $insurance->maturity_days,
                            'restricted_to' => 'insurance_payment',
                        ],
                    ]
                );

                $insurance->update(['status' => 'matured', 'matured_at' => now(), 'refunded_at' => now()]);
                return true;
            });

            $count += $released ? 1 : 0;
        }

        return $count;
    }

    /** @deprecated Insurance balance can no longer pay for products. */
    public function reserveForPurchase(int $customerId, float $requestedAmount, string $reference, array $metadata = []): float
    {
        throw new DomainException('customer_insurance_balance_is_restricted_to_insurance_payment');
    }

    public function debitForInsurance(OrderInsurance $insurance, string $reference, array $metadata = []): float
    {
        if ($insurance->customer_id <= 0 || $insurance->amount <= 0) {
            throw new DomainException('invalid_customer_insurance_payment');
        }

        return $this->debit(
            customerId: (int) $insurance->customer_id,
            amount: (float) $insurance->amount,
            reference: $reference,
            entryType: 'insurance_payment_debit',
            metadata: [
                'order_insurance_id' => $insurance->id,
                'order_id' => $insurance->order_id,
                'restricted_to' => 'insurance_payment',
            ] + $metadata,
            orderInsuranceId: (int) $insurance->id,
            orderId: (int) $insurance->order_id,
        );
    }

    public function debitForGovernance(int $customerId, float $amount, string $reference, array $metadata = []): float
    {
        return $this->debit($customerId, $amount, $reference, 'admin_governance_debit', $metadata);
    }

    public function creditFromGovernance(int $customerId, float $amount, string $reference, array $metadata = []): float
    {
        if ($amount <= 0) throw new DomainException('insurance_action_amount_must_be_positive');

        return DB::transaction(function () use ($customerId, $amount, $reference, $metadata): float {
            User::query()->lockForUpdate()->findOrFail($customerId);
            $existing = CustomerInsuranceLedgerEntry::query()->where('reference', $reference)->first();
            if ($existing) return (float) $existing->credit;

            CustomerInsuranceLedgerEntry::query()->create([
                'customer_id' => $customerId,
                'entry_type' => 'admin_governance_credit',
                'credit' => round($amount, 2), 'debit' => 0,
                'reference' => $reference,
                'metadata' => ['restricted_to' => 'insurance_payment'] + $metadata,
            ]);
            return round($amount, 2);
        });
    }

    private function debit(
        int $customerId,
        float $amount,
        string $reference,
        string $entryType,
        array $metadata = [],
        ?int $orderInsuranceId = null,
        ?int $orderId = null,
    ): float {
        if ($amount <= 0) throw new DomainException('insurance_payment_amount_must_be_positive');

        return DB::transaction(function () use ($customerId, $amount, $reference, $entryType, $metadata, $orderInsuranceId, $orderId): float {
            User::query()->lockForUpdate()->findOrFail($customerId);
            $existing = CustomerInsuranceLedgerEntry::query()->where('reference', $reference)->first();
            if ($existing) return (float) $existing->debit;

            $available = (float) CustomerInsuranceLedgerEntry::query()
                ->where('customer_id', $customerId)->lockForUpdate()
                ->selectRaw('COALESCE(SUM(credit - debit), 0) as balance')->value('balance');
            if ($available + 0.000001 < $amount) {
                throw new DomainException('customer_insurance_balance_is_insufficient');
            }

            CustomerInsuranceLedgerEntry::query()->create([
                'customer_id' => $customerId,
                'order_insurance_id' => $orderInsuranceId,
                'order_id' => $orderId,
                'entry_type' => $entryType,
                'credit' => 0, 'debit' => round($amount, 2),
                'reference' => $reference,
                'metadata' => ['restricted_to' => 'insurance_payment'] + $metadata,
            ]);
            return round($amount, 2);
        });
    }

    public function reversePurchaseDebit(int $customerId, string $debitReference, string $reason): void
    {
        // Historical reversal only. New purchase debits are forbidden above.
        DB::transaction(function () use ($customerId, $debitReference, $reason): void {
            $debit = CustomerInsuranceLedgerEntry::query()
                ->where('customer_id', $customerId)
                ->where('reference', $debitReference)
                ->lockForUpdate()
                ->first();
            if (!$debit || $debit->debit <= 0) {
                return;
            }

            CustomerInsuranceLedgerEntry::query()->firstOrCreate(
                ['reference' => $debitReference . '-reversal'],
                [
                    'customer_id' => $customerId,
                    'entry_type' => 'purchase_reversal',
                    'credit' => $debit->debit,
                    'debit' => 0,
                    'metadata' => ['reason' => $reason, 'reverses' => $debitReference],
                ]
            );
        });
    }

    public function recentEntries(int $customerId, int $limit = 20): Collection
    {
        return CustomerInsuranceLedgerEntry::query()
            ->where('customer_id', $customerId)
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
