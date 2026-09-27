<?php

namespace App\Services;

use App\Models\CustomerInsuranceLedgerEntry;
use App\Models\InsuranceIncentive;
use App\Models\InsuranceIncentiveRedemption;
use App\Models\OrderInsurance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerInsuranceIncentiveService
{
    public function grantEligibleWelcomeCredits(int $customerId): float
    {
        if (! $this->tablesReady()) return 0.0;
        $customer = User::query()->find($customerId);
        if (! $customer) return 0.0;
        if (InsuranceIncentiveRedemption::query()->where('customer_id', $customerId)
            ->where('redemption_type', 'welcome_credit')->exists()) return 0.0;

        $incentive = $this->eligible('welcome_credit', $customer)->first();
        return $incentive ? round($this->grant($incentive, $customer), 2) : 0.0;
    }

    public function discountFor(?int $customerId, float $originalAmount, array $excludedIncentiveIds = []): array
    {
        $empty = ['incentive_id' => null, 'discount_amount' => 0.0, 'incentive_snapshot' => null];
        if (! $customerId || $originalAmount <= 0 || ! $this->tablesReady()) return $empty;
        $customer = User::query()->find($customerId);
        if (! $customer) return $empty;

        $incentive = $this->eligible('premium_discount', $customer)
            ->first(fn (InsuranceIncentive $candidate) => ! in_array((int) $candidate->id, $excludedIncentiveIds, true)
                && $this->remainingUsage($candidate, $customerId) > 0);
        if (! $incentive) return $empty;

        $discount = $incentive->value_type === 'percentage'
            ? round($originalAmount * (float) $incentive->value / 100, 2)
            : (float) $incentive->value;
        if ($incentive->maximum_amount !== null) {
            $discount = min($discount, (float) $incentive->maximum_amount);
        }
        $discount = round(min($originalAmount, max(0, $discount)), 2);

        return [
            'incentive_id' => $incentive->id,
            'discount_amount' => $discount,
            'incentive_snapshot' => [
                'id' => $incentive->id, 'name' => $incentive->name, 'code' => $incentive->code,
                'type' => $incentive->incentive_type, 'value_type' => $incentive->value_type,
                'value' => (float) $incentive->value, 'maximum_amount' => $incentive->maximum_amount,
            ],
        ];
    }

    public function recordDiscount(OrderInsurance $insurance): void
    {
        if (! $insurance->insurance_incentive_id || $insurance->discount_amount <= 0 || ! $this->tablesReady()) return;
        InsuranceIncentiveRedemption::query()->firstOrCreate(
            ['reference' => 'insurance-discount-order-' . $insurance->order_id],
            [
                'insurance_incentive_id' => $insurance->insurance_incentive_id,
                'customer_id' => $insurance->customer_id, 'order_insurance_id' => $insurance->id,
                'redemption_type' => 'premium_discount', 'amount' => $insurance->discount_amount,
                'metadata' => ['original_amount' => $insurance->original_amount, 'charged_amount' => $insurance->amount],
            ]
        );
    }

    private function grant(InsuranceIncentive $incentive, User $customer): float
    {
        return DB::transaction(function () use ($incentive, $customer): float {
            User::query()->lockForUpdate()->findOrFail($customer->id);
            $reference = 'insurance-welcome-' . $incentive->id . '-customer-' . $customer->id;
            if (InsuranceIncentiveRedemption::query()->where('reference', $reference)->exists()) return 0.0;
            $amount = round(max(0, (float) $incentive->value), 2);
            if ($amount <= 0) return 0.0;

            CustomerInsuranceLedgerEntry::query()->firstOrCreate(
                ['reference' => $reference],
                [
                    'customer_id' => $customer->id, 'entry_type' => 'welcome_credit',
                    'credit' => $amount, 'debit' => 0,
                    'metadata' => ['incentive_id' => $incentive->id, 'withdrawable' => false],
                ]
            );
            InsuranceIncentiveRedemption::query()->create([
                'insurance_incentive_id' => $incentive->id, 'customer_id' => $customer->id,
                'redemption_type' => 'welcome_credit', 'amount' => $amount, 'reference' => $reference,
                'metadata' => ['ledger_reference' => $reference],
            ]);
            return $amount;
        });
    }

    private function eligible(string $type, User $customer)
    {
        $ageDays = max(0, now()->diffInDays($customer->created_at, true));
        return InsuranceIncentive::query()->where('incentive_type', $type)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('priority')->orderBy('id')->get()
            ->filter(function (InsuranceIncentive $incentive) use ($ageDays): bool {
                if ($ageDays < $incentive->minimum_account_age_days) return false;
                if ($incentive->maximum_account_age_days !== null && $ageDays > $incentive->maximum_account_age_days) return false;
                if ($incentive->new_accounts_only && $incentive->maximum_account_age_days === null && $ageDays > 30) return false;
                return true;
            });
    }

    private function remainingUsage(InsuranceIncentive $incentive, int $customerId): int
    {
        $used = InsuranceIncentiveRedemption::query()->where('insurance_incentive_id', $incentive->id)
            ->where('customer_id', $customerId)->where('redemption_type', 'premium_discount')->count();
        return max(0, (int) $incentive->usage_limit_per_customer - $used);
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('insurance_incentives')
            && Schema::hasTable('insurance_incentive_redemptions')
            && Schema::hasTable('customer_insurance_ledger_entries');
    }
}
