<?php

namespace App\Services;

use App\Models\CustomerInsuranceLedgerEntry;
use App\Models\InsuranceBalanceAction;
use App\Models\OrderInsurance;
use App\Models\Seller;
use App\Models\SellerOrderInsurance;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class InsuranceBalanceGovernanceService
{
    public function __construct(
        private readonly CustomerInsuranceBalanceService $customerBalances,
        private readonly SellerLedgerService $sellerLedger,
    ) {}

    public function hold(
        string $subjectType,
        int $subjectId,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context = [],
    ): InsuranceBalanceAction {
        return $this->debitAction(
            $subjectType, $subjectId, InsuranceBalanceAction::ACTION_HOLD,
            $amount, $reference, $reason, $adminId, $context,
        );
    }

    public function confiscateAvailable(
        string $subjectType,
        int $subjectId,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context = [],
    ): InsuranceBalanceAction {
        return $this->debitAction(
            $subjectType, $subjectId, InsuranceBalanceAction::ACTION_CONFISCATE,
            $amount, $reference, $reason, $adminId, $context,
        );
    }

    public function confiscateCustomerInsurance(
        OrderInsurance $insurance,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context = [],
    ): InsuranceBalanceAction {
        return $this->confiscateInsuranceRecord('customer', $insurance->id, $amount, $reference, $reason, $adminId, $context);
    }

    public function confiscateSellerInsurance(
        SellerOrderInsurance $insurance,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context = [],
    ): InsuranceBalanceAction {
        return $this->confiscateInsuranceRecord('seller', $insurance->id, $amount, $reference, $reason, $adminId, $context);
    }

    public function releaseHold(
        InsuranceBalanceAction $hold,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context = [],
    ): InsuranceBalanceAction {
        return $this->creditChildAction(
            $hold, InsuranceBalanceAction::ACTION_RELEASE, $amount,
            $reference, $reason, $adminId, $context,
        );
    }

    public function confiscateHold(
        InsuranceBalanceAction $hold,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context = [],
    ): InsuranceBalanceAction {
        $this->assertActionInput($amount, $reference, $reason);

        return DB::transaction(function () use ($hold, $amount, $reference, $reason, $adminId, $context): InsuranceBalanceAction {
            if ($existing = InsuranceBalanceAction::query()->where('reference', $reference)->first()) {
                $this->assertExistingMatches($existing, $hold->subject_type, (int) $hold->subject_id, InsuranceBalanceAction::ACTION_CONFISCATE, $amount, $hold->id);
                return $existing;
            }
            $locked = InsuranceBalanceAction::query()->lockForUpdate()->findOrFail($hold->id);
            if ($locked->action !== InsuranceBalanceAction::ACTION_HOLD) {
                throw new DomainException('insurance_action_must_reference_a_hold');
            }
            $this->assertRemaining($locked, $amount);

            // The amount was already removed from available balance by the hold.
            // Confiscation finalises all or part of that hold without a second debit.
            return $this->createAction(
                $locked->subject_type, $locked->subject_id,
                InsuranceBalanceAction::ACTION_CONFISCATE, $amount, $reference,
                $reason, $adminId, $context, $locked,
            );
        });
    }

    public function reverseConfiscation(
        InsuranceBalanceAction $confiscation,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context = [],
    ): InsuranceBalanceAction {
        return $this->creditChildAction(
            $confiscation, InsuranceBalanceAction::ACTION_REVERSE_CONFISCATION,
            $amount, $reference, $reason, $adminId, $context,
        );
    }

    private function confiscateInsuranceRecord(
        string $subjectType,
        int $insuranceId,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context,
    ): InsuranceBalanceAction {
        $this->assertActionInput($amount, $reference, $reason);

        return DB::transaction(function () use ($subjectType, $insuranceId, $amount, $reference, $reason, $adminId, $context): InsuranceBalanceAction {
            $record = $subjectType === InsuranceBalanceAction::SUBJECT_CUSTOMER
                ? OrderInsurance::query()->lockForUpdate()->findOrFail($insuranceId)
                : SellerOrderInsurance::query()->lockForUpdate()->findOrFail($insuranceId);
            $subjectId = (int) ($subjectType === InsuranceBalanceAction::SUBJECT_CUSTOMER ? $record->customer_id : $record->seller_id);

            if ($existing = InsuranceBalanceAction::query()->where('reference', $reference)->first()) {
                $this->assertExistingMatches($existing, $subjectType, $subjectId, InsuranceBalanceAction::ACTION_CONFISCATE, $amount);
                return $existing;
            }
            if ($record->payment_status !== 'paid') {
                throw new DomainException('only_paid_insurance_can_be_confiscated');
            }

            $remaining = round((float) $record->amount - (float) $record->confiscated_amount, 2);
            if ($amount > $remaining + 0.000001) {
                throw new DomainException('insurance_action_amount_exceeds_remaining_balance');
            }

            $balanceDebited = $subjectType === InsuranceBalanceAction::SUBJECT_CUSTOMER
                ? $record->matured_at !== null
                : $record->reusable_released_at !== null;
            if ($balanceDebited) {
                $this->lockSubject($subjectType, $subjectId);
                $this->debitInsuranceBalance($subjectType, $subjectId, $amount, $reference, InsuranceBalanceAction::ACTION_CONFISCATE, $context);
            }

            $newConfiscated = round((float) $record->confiscated_amount + $amount, 2);
            $record->update([
                'confiscation_status' => $newConfiscated + 0.000001 >= (float) $record->amount ? 'confiscated' : 'partial',
                'confiscated_amount' => $newConfiscated,
                'confiscated_by_admin_id' => $adminId,
                'confiscated_at' => now(),
                'confiscation_reason' => trim($reason),
            ]);

            return $this->createAction(
                $subjectType, $subjectId, InsuranceBalanceAction::ACTION_CONFISCATE,
                $amount, $reference, $reason, $adminId,
                [
                    'order_id' => $record->order_id,
                    'order_insurance_id' => $subjectType === InsuranceBalanceAction::SUBJECT_CUSTOMER ? $record->id : null,
                    'seller_order_insurance_id' => $subjectType === InsuranceBalanceAction::SUBJECT_SELLER ? $record->id : null,
                    'balance_debited' => $balanceDebited,
                    'source' => 'insurance_record',
                ] + $context,
            );
        });
    }

    public function heldBalance(string $subjectType, int $subjectId): float
    {
        // Kept as explicit queries for database portability and audit clarity.
        $holds = (float) InsuranceBalanceAction::query()
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('action', InsuranceBalanceAction::ACTION_HOLD)->sum('amount');
        $closed = (float) InsuranceBalanceAction::query()
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereIn('action', [InsuranceBalanceAction::ACTION_RELEASE, InsuranceBalanceAction::ACTION_CONFISCATE])
            ->whereNotNull('parent_action_id')->sum('amount');

        return round(max(0, $holds - $closed), 2);
    }

    private function debitAction(
        string $subjectType,
        int $subjectId,
        string $action,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context,
    ): InsuranceBalanceAction {
        $this->assertActionInput($amount, $reference, $reason);
        $this->assertSubject($subjectType, $subjectId);

        return DB::transaction(function () use ($subjectType, $subjectId, $action, $amount, $reference, $reason, $adminId, $context): InsuranceBalanceAction {
            if ($existing = InsuranceBalanceAction::query()->where('reference', $reference)->first()) {
                $this->assertExistingMatches($existing, $subjectType, $subjectId, $action, $amount);
                return $existing;
            }
            $this->lockSubject($subjectType, $subjectId);
            $this->debitInsuranceBalance($subjectType, $subjectId, $amount, $reference, $action, $context);

            return $this->createAction(
                $subjectType, $subjectId, $action, $amount,
                $reference, $reason, $adminId, ['balance_debited' => true] + $context,
            );
        });
    }

    private function creditChildAction(
        InsuranceBalanceAction $parent,
        string $action,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context,
    ): InsuranceBalanceAction {
        $this->assertActionInput($amount, $reference, $reason);

        return DB::transaction(function () use ($parent, $action, $amount, $reference, $reason, $adminId, $context): InsuranceBalanceAction {
            if ($existing = InsuranceBalanceAction::query()->where('reference', $reference)->first()) {
                $this->assertExistingMatches($existing, $parent->subject_type, (int) $parent->subject_id, $action, $amount, $parent->id);
                return $existing;
            }
            $locked = InsuranceBalanceAction::query()->lockForUpdate()->findOrFail($parent->id);
            $expected = $action === InsuranceBalanceAction::ACTION_RELEASE
                ? InsuranceBalanceAction::ACTION_HOLD
                : InsuranceBalanceAction::ACTION_CONFISCATE;
            if ($locked->action !== $expected) {
                throw new DomainException('invalid_insurance_balance_parent_action');
            }
            $this->assertRemaining($locked, $amount);
            $balanceWasDebited = (bool) data_get($locked->metadata, 'balance_debited', true);
            if ($balanceWasDebited) {
                $this->lockSubject($locked->subject_type, (int) $locked->subject_id);
                $this->creditInsuranceBalance($locked->subject_type, (int) $locked->subject_id, $amount, $reference, $action, $context);
            }

            $created = $this->createAction(
                $locked->subject_type, (int) $locked->subject_id, $action,
                $amount, $reference, $reason, $adminId,
                ['balance_debited' => $balanceWasDebited] + $context, $locked,
            );
            if ($action === InsuranceBalanceAction::ACTION_REVERSE_CONFISCATION) {
                $this->reduceLinkedConfiscation($locked, $amount, $adminId, $reason);
            }

            return $created;
        });
    }

    private function reduceLinkedConfiscation(InsuranceBalanceAction $confiscation, float $amount, ?int $adminId, string $reason): void
    {
        $record = $confiscation->order_insurance_id
            ? OrderInsurance::query()->lockForUpdate()->find($confiscation->order_insurance_id)
            : ($confiscation->seller_order_insurance_id
                ? SellerOrderInsurance::query()->lockForUpdate()->find($confiscation->seller_order_insurance_id)
                : null);
        if (! $record) return;

        $remaining = round(max(0, (float) $record->confiscated_amount - $amount), 2);
        $record->update([
            'confiscation_status' => $remaining <= 0 ? 'none' : 'partial',
            'confiscated_amount' => $remaining,
            'confiscated_by_admin_id' => $adminId,
            'confiscated_at' => $remaining <= 0 ? null : now(),
            'confiscation_reason' => $remaining <= 0 ? null : trim($reason),
        ]);
    }

    private function debitInsuranceBalance(string $subjectType, int $subjectId, float $amount, string $reference, string $action, array $context): void
    {
        if ($subjectType === InsuranceBalanceAction::SUBJECT_CUSTOMER) {
            $this->customerBalances->debitForGovernance($subjectId, $amount, 'governance-debit-'.sha1($reference), [
                'action' => $action, 'action_reference' => $reference,
            ] + $context);
            return;
        }

        $this->sellerLedger->assertSufficient($subjectId, SellerLedgerService::ORDER_INSURANCE_CREDIT, $amount);
        $this->sellerLedger->post(
            sellerId: $subjectId,
            eventType: 'seller_insurance_'.$action,
            groupKey: 'insurance-governance:'.$reference,
            movements: [[
                'bucket' => SellerLedgerService::ORDER_INSURANCE_CREDIT,
                'direction' => 'debit', 'amount' => $amount,
            ]],
            metadata: ['restricted_to' => 'insurance_payment', 'action_reference' => $reference] + $context,
        );
    }

    private function creditInsuranceBalance(string $subjectType, int $subjectId, float $amount, string $reference, string $action, array $context): void
    {
        if ($subjectType === InsuranceBalanceAction::SUBJECT_CUSTOMER) {
            $this->customerBalances->creditFromGovernance($subjectId, $amount, 'governance-credit-'.sha1($reference), [
                'action' => $action, 'action_reference' => $reference,
            ] + $context);
            return;
        }

        $this->sellerLedger->post(
            sellerId: $subjectId,
            eventType: 'seller_insurance_'.$action,
            groupKey: 'insurance-governance:'.$reference,
            movements: [[
                'bucket' => SellerLedgerService::ORDER_INSURANCE_CREDIT,
                'direction' => 'credit', 'amount' => $amount,
            ]],
            metadata: ['restricted_to' => 'insurance_payment', 'action_reference' => $reference] + $context,
        );
    }

    private function createAction(
        string $subjectType,
        int $subjectId,
        string $action,
        float $amount,
        string $reference,
        string $reason,
        ?int $adminId,
        array $context,
        ?InsuranceBalanceAction $parent = null,
    ): InsuranceBalanceAction {
        return InsuranceBalanceAction::query()->create([
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'action' => $action, 'amount' => round($amount, 2),
            'order_id' => $context['order_id'] ?? $parent?->order_id,
            'order_insurance_id' => $context['order_insurance_id'] ?? $parent?->order_insurance_id,
            'seller_order_insurance_id' => $context['seller_order_insurance_id'] ?? $parent?->seller_order_insurance_id,
            'parent_action_id' => $parent?->id, 'reference' => $reference,
            'reason' => trim($reason), 'evidence' => $context['evidence'] ?? null,
            'metadata' => array_diff_key($context, array_flip(['evidence'])), 'admin_id' => $adminId,
        ]);
    }

    private function assertRemaining(InsuranceBalanceAction $parent, float $amount): void
    {
        $used = (float) InsuranceBalanceAction::query()->where('parent_action_id', $parent->id)->sum('amount');
        if ($amount > round((float) $parent->amount - $used, 2) + 0.000001) {
            throw new DomainException('insurance_action_amount_exceeds_remaining_balance');
        }
    }

    private function lockSubject(string $subjectType, int $subjectId): void
    {
        if ($subjectType === InsuranceBalanceAction::SUBJECT_CUSTOMER) {
            User::query()->lockForUpdate()->findOrFail($subjectId);
            return;
        }
        Seller::query()->lockForUpdate()->findOrFail($subjectId);
    }

    private function assertSubject(string $subjectType, int $subjectId): void
    {
        if (! in_array($subjectType, [InsuranceBalanceAction::SUBJECT_CUSTOMER, InsuranceBalanceAction::SUBJECT_SELLER], true) || $subjectId <= 0) {
            throw new DomainException('invalid_insurance_balance_subject');
        }
    }

    private function assertActionInput(float $amount, string $reference, string $reason): void
    {
        if ($amount <= 0) throw new DomainException('insurance_action_amount_must_be_positive');
        if (trim($reference) === '') throw new DomainException('insurance_action_reference_is_required');
        if (trim($reason) === '') throw new DomainException('insurance_action_reason_is_required');
    }

    private function assertExistingMatches(
        InsuranceBalanceAction $existing,
        string $subjectType,
        int $subjectId,
        string $action,
        float $amount,
        ?int $parentActionId = null,
    ): void {
        if (
            $existing->subject_type !== $subjectType
            || (int) $existing->subject_id !== $subjectId
            || $existing->action !== $action
            || abs((float) $existing->amount - round($amount, 2)) > 0.000001
            || (int) ($existing->parent_action_id ?? 0) !== (int) ($parentActionId ?? 0)
        ) {
            throw new DomainException('insurance_action_idempotency_conflict');
        }
    }
}
