<?php

namespace App\Services;

use App\Models\Seller;
use App\Models\SellerInsurance;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerInsuranceService
{
    public function __construct(
        private readonly SellerLedgerService $ledger,
    ) {}

    public function payFromOperatingBalance(SellerInsurance $insurance): array
    {
        throw new DomainException('seller_operating_balance_cannot_pay_insurance');
    }

    public function getSettings(): array
    {
        return [
            'enabled' => (bool) getWebConfig(name: 'seller_insurance_status'),
            'amount' => (float) (getWebConfig(name: 'seller_insurance_amount') ?? 0),
            'repayment_after_forfeiture' => (bool) (getWebConfig(name: 'seller_insurance_repayment_after_forfeiture') ?? 1),
        ];
    }

    public function getSummary(Seller $seller): array
    {
        $settings = $this->getSettings();
        $activeInsurance = $seller->insurances()
            ->whereIn('status', [SellerInsurance::STATUS_PAID, SellerInsurance::STATUS_WAIVED])
            ->latest('id')
            ->first();
        $latestInsurance = $seller->insurances()->latest('id')->first();
        $forfeitedWithoutRepayment = $latestInsurance?->status === SellerInsurance::STATUS_FORFEITED
            && ! $settings['repayment_after_forfeiture'];
        $required = $settings['enabled'] && ! $activeInsurance && ! $forfeitedWithoutRepayment;

        return [
            'settings' => $settings,
            'required' => $required,
            'active' => (bool) $activeInsurance || $forfeitedWithoutRepayment || ! $settings['enabled'],
            'active_insurance' => $activeInsurance,
            'latest_insurance' => $latestInsurance,
            'pending_review' => $latestInsurance?->status === SellerInsurance::STATUS_PENDING_REVIEW,
            'can_pay' => $required && $latestInsurance?->status !== SellerInsurance::STATUS_PENDING_REVIEW,
        ];
    }

    public function getOrCreatePayableInsurance(Seller $seller): SellerInsurance
    {
        return DB::transaction(function () use ($seller) {
            $seller = Seller::query()->lockForUpdate()->findOrFail($seller->id);
            $settings = $this->getSettings();

            if (! $settings['enabled']) {
                throw new DomainException('seller_insurance_is_not_enabled');
            }

            $activeInsurance = $seller->insurances()
                ->whereIn('status', [SellerInsurance::STATUS_PAID, SellerInsurance::STATUS_WAIVED])
                ->latest('id')
                ->first();
            if ($activeInsurance) {
                throw new DomainException('seller_insurance_is_already_active');
            }

            $latestInsurance = $seller->insurances()->latest('id')->lockForUpdate()->first();
            if ($latestInsurance?->status === SellerInsurance::STATUS_FORFEITED
                && ! $settings['repayment_after_forfeiture']) {
                throw new DomainException('new_insurance_is_not_required_after_forfeiture');
            }

            if ($latestInsurance?->status === SellerInsurance::STATUS_PENDING_REVIEW) {
                throw new DomainException('seller_insurance_payment_is_pending_admin_review');
            }

            if ((float) $settings['amount'] <= 0) {
                throw new DomainException('seller_insurance_amount_is_not_configured');
            }

            if ($latestInsurance?->status === SellerInsurance::STATUS_PENDING
                && $latestInsurance->payment_status === 'unpaid') {
                if (! $latestInsurance->payment_request_id) {
                    $latestInsurance->update(['amount' => $settings['amount']]);
                }

                return $latestInsurance->fresh();
            }

            return SellerInsurance::create([
                'seller_id' => $seller->id,
                'transaction_id' => (string) Str::uuid(),
                'amount' => $settings['amount'],
                'status' => SellerInsurance::STATUS_PENDING,
                'payment_status' => 'unpaid',
                'metadata' => [
                    'created_from' => 'seller_insurance_payment',
                    'settings_snapshot' => $settings,
                ],
            ]);
        });
    }

    public function attachPaymentRequest(SellerInsurance $insurance, string $paymentRequestId): SellerInsurance
    {
        return DB::transaction(function () use ($insurance, $paymentRequestId) {
            $insurance = SellerInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($insurance->payment_status === 'unpaid' && $insurance->status === SellerInsurance::STATUS_PENDING) {
                $insurance->update(['payment_request_id' => $paymentRequestId]);
            }

            return $insurance->fresh();
        });
    }

    public function submitOfflinePayment(SellerInsurance $insurance, array $offlinePayment): SellerInsurance
    {
        return DB::transaction(function () use ($insurance, $offlinePayment) {
            $insurance = SellerInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($insurance->payment_status !== 'unpaid' || $insurance->status !== SellerInsurance::STATUS_PENDING) {
                throw new DomainException('seller_insurance_is_not_available_for_offline_payment');
            }

            $metadata = $insurance->metadata ?: [];
            $metadata['offline_payment'] = $offlinePayment;
            $insurance->update([
                'status' => SellerInsurance::STATUS_PENDING_REVIEW,
                'payment_method' => 'offline_payment',
                'payment_reference' => 'offline-review:'.($offlinePayment['method_id'] ?? 'manual'),
                'metadata' => $metadata,
            ]);

            return $insurance->fresh();
        });
    }

    public function markPaid(SellerInsurance $insurance, array $paymentData, ?int $reviewedByAdminId = null, ?string $reviewNote = null): array
    {
        return DB::transaction(function () use ($insurance, $paymentData, $reviewedByAdminId, $reviewNote) {
            Seller::query()->whereKey($insurance->seller_id)->lockForUpdate()->first();
            $insurance = SellerInsurance::query()->lockForUpdate()->find($insurance->id);

            if (! $insurance) {
                return ['status' => 0, 'message' => 'seller_insurance_not_found'];
            }

            if ($insurance->payment_status === 'paid' && $insurance->status === SellerInsurance::STATUS_PAID) {
                return ['status' => 1, 'message' => 'already_paid', 'insurance' => $insurance];
            }

            if ($insurance->payment_status !== 'unpaid'
                || ! in_array($insurance->status, [SellerInsurance::STATUS_PENDING, SellerInsurance::STATUS_PENDING_REVIEW], true)) {
                return ['status' => 0, 'message' => 'seller_insurance_cannot_be_activated'];
            }

            if ((float) $insurance->amount <= 0) {
                return ['status' => 0, 'message' => 'invalid_seller_insurance_amount'];
            }

            $paymentRequestId = $paymentData['id'] ?? $insurance->payment_request_id;
            $metadata = $insurance->metadata ?: [];
            $metadata['payment'] = [
                'payment_request_id' => $paymentRequestId,
                'payment_method' => $paymentData['payment_method'] ?? $insurance->payment_method,
                'transaction_id' => $paymentData['transaction_id'] ?? null,
                'payment_amount' => $paymentData['payment_amount'] ?? $insurance->amount,
                'currency_code' => $paymentData['currency_code'] ?? null,
                'paid_at' => now()->toDateTimeString(),
            ];

            if ($reviewedByAdminId) {
                $metadata['offline_payment_review'] = [
                    'action' => 'approved',
                    'reviewed_by_admin_id' => $reviewedByAdminId,
                    'reviewed_at' => now()->toDateTimeString(),
                    'note' => $reviewNote,
                ];
            }

            $insurance->update([
                'status' => SellerInsurance::STATUS_PAID,
                'payment_status' => 'paid',
                'payment_method' => $paymentData['payment_method'] ?? $insurance->payment_method,
                'payment_reference' => $paymentData['transaction_id'] ?? $insurance->payment_reference,
                'payment_request_id' => $paymentRequestId,
                'reviewed_by_admin_id' => $reviewedByAdminId,
                'paid_at' => now(),
                'reviewed_at' => $reviewedByAdminId ? now() : null,
                'metadata' => $metadata,
            ]);

            return ['status' => 1, 'message' => 'paid', 'insurance' => $insurance->fresh()];
        });
    }

    public function rejectOfflinePayment(SellerInsurance $insurance, int $reviewedByAdminId, ?string $reviewNote = null): SellerInsurance
    {
        return DB::transaction(function () use ($insurance, $reviewedByAdminId, $reviewNote) {
            $insurance = SellerInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($insurance->payment_status !== 'unpaid' || $insurance->status !== SellerInsurance::STATUS_PENDING_REVIEW) {
                throw new DomainException('seller_insurance_offline_review_not_found');
            }

            $metadata = $insurance->metadata ?: [];
            $metadata['offline_payment_review'] = [
                'action' => 'rejected',
                'reviewed_by_admin_id' => $reviewedByAdminId,
                'reviewed_at' => now()->toDateTimeString(),
                'note' => $reviewNote,
            ];

            $insurance->update([
                'status' => SellerInsurance::STATUS_REJECTED,
                'reviewed_by_admin_id' => $reviewedByAdminId,
                'reviewed_at' => now(),
                'metadata' => $metadata,
            ]);

            return $insurance->fresh();
        });
    }

    public function recordPaymentFailure(SellerInsurance $insurance, array $paymentData): void
    {
        DB::transaction(function () use ($insurance, $paymentData) {
            $insurance = SellerInsurance::query()->lockForUpdate()->find($insurance->id);
            if (! $insurance || $insurance->payment_status === 'paid') {
                return;
            }

            $metadata = $insurance->metadata ?: [];
            $metadata['last_payment_failure'] = [
                'payment_request_id' => $paymentData['id'] ?? null,
                'payment_method' => $paymentData['payment_method'] ?? null,
                'failed_at' => now()->toDateTimeString(),
            ];
            $insurance->update(['metadata' => $metadata]);
        });
    }
}
