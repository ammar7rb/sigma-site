<?php

namespace App\Services;

use App\Models\InsuranceRule;
use App\Models\InsuranceSubjectOverride;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class InsuranceRuleEngineService
{
    public function resolve(
        string $subjectType,
        float $orderAmount,
        CarbonInterface|\DateTimeInterface|string|null $accountCreatedAt = null,
        ?array $fallback = null,
        ?int $subjectId = null,
    ): array {
        $amount = round(max(0, $orderAmount), 2);
        $ageDays = $accountCreatedAt ? max(0, now()->diffInDays($accountCreatedAt, true)) : 0;

        $override = $subjectId && Schema::hasTable('insurance_subject_overrides')
            ? InsuranceSubjectOverride::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
                ->where('is_active', true)->first()
            : null;
        if ($override?->mode === 'exempt') {
            return [
                'applicable' => false, 'amount' => 0.0, 'original_amount' => 0.0, 'order_amount' => $amount,
                'calculation_type' => 'fixed', 'calculation_value' => 0.0, 'rule_id' => null,
                'rule_code' => 'account-exemption', 'rule_type' => 'account_override', 'priority' => 0,
                'account_age_days' => $ageDays,
                'rule_snapshot' => ['account_override' => ['id' => $override->id, 'mode' => 'exempt', 'reason' => $override->reason]],
            ];
        }
        if ($override?->mode === 'required') {
            $type = in_array($override->calculation_type, ['percentage', 'fixed'], true) ? $override->calculation_type : 'percentage';
            $value = max(0, (float) $override->calculation_value);
            $originalAmount = $amount > 0 && $value > 0 ? ($type === 'fixed' ? $value : round($amount * $value / 100, 2)) : 0.0;
            return [
                'applicable' => $originalAmount > 0, 'amount' => round($originalAmount, 2), 'original_amount' => round($originalAmount, 2),
                'order_amount' => $amount, 'calculation_type' => $type, 'calculation_value' => $value,
                'rule_id' => null, 'rule_code' => 'account-required-insurance', 'rule_type' => 'account_override',
                'priority' => 0, 'account_age_days' => $ageDays,
                'rule_snapshot' => ['account_override' => ['id' => $override->id, 'mode' => 'required', 'reason' => $override->reason, 'calculation_type' => $type, 'calculation_value' => $value]],
            ];
        }
        $rule = null;

        if (Schema::hasTable('insurance_rules')) {
            $rule = InsuranceRule::query()
                ->where('subject_type', $subjectType)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->first(fn (InsuranceRule $candidate) => $this->matches($candidate, $amount, $ageDays));
        }

        $snapshot = $rule ? $this->snapshot($rule) : ($fallback ?? []);
        $eligible = $rule !== null || $this->matchesSnapshot($snapshot, $amount, $ageDays);
        $type = in_array(($snapshot['calculation_type'] ?? null), ['percentage', 'fixed'], true)
            ? $snapshot['calculation_type'] : 'percentage';
        $value = max(0, (float) ($snapshot['calculation_value'] ?? 0));
        $originalAmount = $eligible && $value > 0
            ? ($type === 'fixed' ? $value : round($amount * $value / 100, 2))
            : 0.0;

        return [
            'applicable' => $amount > 0 && $originalAmount > 0,
            'amount' => round($originalAmount, 2),
            'original_amount' => round($originalAmount, 2),
            'order_amount' => $amount,
            'calculation_type' => $type,
            'calculation_value' => $value,
            'rule_id' => $rule?->id,
            'rule_code' => $snapshot['code'] ?? 'legacy-default',
            'rule_type' => $snapshot['rule_type'] ?? 'default',
            'priority' => (int) ($snapshot['priority'] ?? 1000),
            'account_age_days' => $ageDays,
            'rule_snapshot' => $snapshot,
        ];
    }

    private function matchesSnapshot(array $snapshot, float $amount, int $ageDays): bool
    {
        $minimum = (float) ($snapshot['minimum_order_amount'] ?? 0);
        $maximum = $snapshot['maximum_order_amount'] ?? null;
        $minimumAge = (int) ($snapshot['minimum_account_age_days'] ?? 0);
        $maximumAge = $snapshot['maximum_account_age_days'] ?? null;
        return $amount > 0
            && $amount > $minimum
            && ($maximum === null || $amount <= (float) $maximum)
            && $ageDays >= $minimumAge
            && ($maximumAge === null || $ageDays <= (int) $maximumAge);
    }

    private function matches(InsuranceRule $rule, float $amount, int $ageDays): bool
    {
        if ($amount <= 0 || $amount <= (float) $rule->minimum_order_amount) return false;
        if ($rule->maximum_order_amount !== null && $amount > (float) $rule->maximum_order_amount) return false;
        if ($ageDays < (int) $rule->minimum_account_age_days) return false;
        if ($rule->maximum_account_age_days !== null && $ageDays > (int) $rule->maximum_account_age_days) return false;
        return (float) $rule->calculation_value > 0;
    }

    public function snapshot(InsuranceRule $rule): array
    {
        return [
            'id' => $rule->id, 'subject_type' => $rule->subject_type, 'name' => $rule->name,
            'code' => $rule->code, 'rule_type' => $rule->rule_type, 'priority' => $rule->priority,
            'minimum_order_amount' => (float) $rule->minimum_order_amount,
            'maximum_order_amount' => $rule->maximum_order_amount !== null ? (float) $rule->maximum_order_amount : null,
            'minimum_account_age_days' => (int) $rule->minimum_account_age_days,
            'maximum_account_age_days' => $rule->maximum_account_age_days !== null ? (int) $rule->maximum_account_age_days : null,
            'calculation_type' => $rule->calculation_type,
            'calculation_value' => (float) $rule->calculation_value,
            'starts_at' => $rule->starts_at?->toIso8601String(), 'ends_at' => $rule->ends_at?->toIso8601String(),
        ];
    }
}
