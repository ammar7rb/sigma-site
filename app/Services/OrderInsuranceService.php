<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\OrderInsurance;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class OrderInsuranceService
{
    public const PREPAID_METHODS = ['offline_payment', 'pay_by_wallet'];

    public function isEnabled(): bool
    {
        return (int) (BusinessSetting::where('type', 'customer_order_insurance_status')->value('value') ?? 0) === 1;
    }

    public function isPrepaidMethod(string $paymentMethod): bool
    {
        return $paymentMethod !== 'cash_on_delivery';
    }

    public function appliesToAmount(float $amount): bool
    {
        $threshold = (float) (BusinessSetting::where('type', 'customer_order_insurance_threshold')->value('value') ?? 0);
        return $this->isEnabled()
            && $amount > 0
            && ($threshold <= 0 || $amount > $threshold);
    }

    public function maturityDays(): int
    {
        return max(1, (int) (BusinessSetting::where('type', 'customer_order_insurance_maturity_days')->value('value') ?? 90));
    }

    public function calculate(float $orderAmount, ?int $customerId = null, array $excludedIncentiveIds = []): array
    {
        $threshold = (float) (BusinessSetting::where('type', 'customer_order_insurance_threshold')->value('value') ?? 0);
        $type = BusinessSetting::where('type', 'customer_order_insurance_calculation_type')->value('value') ?: 'percentage';
        $value = (float) (BusinessSetting::where('type', 'customer_order_insurance_calculation_value')->value('value') ?? 0);
        $customer = $customerId ? User::query()->find($customerId) : null;
        $resolved = app(InsuranceRuleEngineService::class)->resolve(
            subjectType: 'customer', orderAmount: $orderAmount, accountCreatedAt: $customer?->created_at,
            fallback: [
                'code' => 'customer-legacy-default', 'rule_type' => 'default', 'priority' => 1000,
                'minimum_order_amount' => $threshold, 'maximum_order_amount' => null,
                'minimum_account_age_days' => 0, 'maximum_account_age_days' => null,
                'calculation_type' => $type, 'calculation_value' => $value,
            ],
            subjectId: $customerId,
        );
        $applicable = $this->isEnabled() && $resolved['applicable'];
        $originalAmount = $applicable ? $resolved['original_amount'] : 0.0;
        $discount = $applicable
            ? app(CustomerInsuranceIncentiveService::class)->discountFor($customerId, $originalAmount, $excludedIncentiveIds)
            : ['incentive_id' => null, 'discount_amount' => 0.0, 'incentive_snapshot' => null];
        $amount = round(max(0, $originalAmount - $discount['discount_amount']), 2);

        $maturityDays = $this->maturityDays();
        return [
            'applicable' => $applicable, 'amount' => $amount, 'original_amount' => $originalAmount,
            'discount_amount' => $discount['discount_amount'], 'threshold' => $threshold,
            'type' => $resolved['calculation_type'], 'value' => $resolved['calculation_value'],
            'orderAmount' => $resolved['order_amount'], 'maturityDays' => $maturityDays,
            'rule_id' => $resolved['rule_id'], 'rule_code' => $resolved['rule_code'],
            'rule_snapshot' => $resolved['rule_snapshot'], 'incentive_id' => $discount['incentive_id'],
            'incentive_snapshot' => $discount['incentive_snapshot'], 'withdrawable' => false,
            'allowed_uses_after_maturity' => ['insurance_payment'],
        ];
    }

    public function calculateCartList(iterable $vendorWiseCartList, ?int $customerId = null): array
    {
        $items = [];
        $total = 0.0;
        $usedIncentives = [];
        foreach ($vendorWiseCartList as $cart) {
            $orderAmount = (float) ($cart['order_amount_with_tax'] ?? 0) - (float) ($cart['refer_and_earn_discount'] ?? 0);
            $calculation = $this->calculate(max(0, $orderAmount), $customerId, $usedIncentives);
            if ($calculation['applicable']) {
                $items[] = $calculation;
                $total += $calculation['amount'];
                if ($calculation['incentive_id']) $usedIncentives[] = (int) $calculation['incentive_id'];
            }
        }
        return [
            'items' => $items, 'total' => round($total, 2), 'applicable' => $items !== [],
            'original_total' => round((float) collect($items)->sum('original_amount'), 2),
            'discount_total' => round((float) collect($items)->sum('discount_amount'), 2),
            'maturity_days' => $this->maturityDays(), 'withdrawable' => false,
            'allowed_uses_after_maturity' => ['insurance_payment'],
        ];
    }

    public function createForOrder(int $orderId, int $customerId, array $calculation, string $paymentMethod, string $paymentStatus): OrderInsurance
    {
        $maturityDays = (int) ($calculation['maturityDays'] ?? $this->maturityDays());
        $attributes = [
            'order_id' => $orderId, 'customer_id' => $customerId,
            'amount' => $calculation['amount'],
            'order_amount' => $calculation['orderAmount'],
            'threshold_amount' => $calculation['threshold'], 'calculation_type' => $calculation['type'],
            'calculation_value' => $calculation['value'], 'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus, 'status' => $paymentStatus === 'paid' ? 'held' : 'pending_payment',
            'maturity_days' => $maturityDays,
            'matures_at' => $paymentStatus === 'paid' ? now()->addDays($maturityDays) : null,
            'metadata' => [
                'configured_at' => now()->toIso8601String(), 'maturity_days' => $maturityDays,
                'incentive_snapshot' => $calculation['incentive_snapshot'] ?? null,
                'withdrawable' => false, 'allowed_uses_after_maturity' => $calculation['allowed_uses_after_maturity'] ?? [],
            ],
        ];
        if (Schema::hasColumn('order_insurances', 'balance_use_policy')) {
            $attributes['balance_use_policy'] = 'insurance_only';
        }
        if (Schema::hasColumn('order_insurances', 'insurance_rule_id')) {
            $attributes += [
                'insurance_rule_id' => $calculation['rule_id'] ?? null,
                'insurance_incentive_id' => $calculation['incentive_id'] ?? null,
                'original_amount' => $calculation['original_amount'] ?? $calculation['amount'],
                'discount_amount' => $calculation['discount_amount'] ?? 0,
                'rule_snapshot' => $calculation['rule_snapshot'] ?? null,
            ];
        }
        $insurance = OrderInsurance::create($attributes);

        app(CustomerInsuranceIncentiveService::class)->recordDiscount($insurance);

        if ($paymentStatus === 'paid') {
            app(CustomerInsuranceBalanceService::class)->recordHold($insurance);
        }

        return $insurance;
    }

    public function markPaid(OrderInsurance $insurance, ?int $adminId = null, ?string $note = null): OrderInsurance
    {
        $maturityDays = $insurance->maturity_days ?: $this->maturityDays();
        $insurance->update([
            'payment_status' => 'paid',
            'status' => 'held',
            'maturity_days' => $maturityDays,
            'matures_at' => now()->addDays($maturityDays),
            'admin_id' => $adminId,
            'admin_note' => $note,
        ]);
        app(CustomerInsuranceBalanceService::class)->recordHold($insurance->fresh());
        return $insurance->fresh();
    }
}
