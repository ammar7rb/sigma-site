<?php

namespace App\Services;

use App\Models\Seller;
use App\Models\SellerBalanceDeposit;
use DomainException;
use Illuminate\Support\Facades\DB;

class SellerBalanceDepositService
{
    public function __construct(private readonly SellerLedgerService $ledger)
    {
    }

    public function createOffline(Seller $seller, array $data): SellerBalanceDeposit
    {
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new DomainException('seller_balance_deposit_amount_invalid');
        }
        $walletTarget = $this->walletTarget($data['wallet_target'] ?? 'operating');
        unset($data['wallet_target']);
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $metadata['wallet_target'] = $walletTarget;
        return SellerBalanceDeposit::create(array_merge($data, [
            'seller_id' => $seller->id,
            'amount' => $amount,
            'source' => SellerBalanceDeposit::SOURCE_OFFLINE,
            'status' => SellerBalanceDeposit::STATUS_PENDING,
            'metadata' => $metadata,
            'idempotency_key' => 'offline-deposit:'.sha1($seller->id.'|'.microtime(true).'|'.bin2hex(random_bytes(8))),
        ]));
    }

    public function createDigital(Seller $seller, float $amount, string $currencyCode, string $paymentMethod, string $walletTarget = 'operating'): SellerBalanceDeposit
    {
        if ($amount <= 0) {
            throw new DomainException('seller_balance_deposit_amount_invalid');
        }
        $walletTarget = $this->walletTarget($walletTarget);
        return SellerBalanceDeposit::create([
            'seller_id' => $seller->id,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'source' => SellerBalanceDeposit::SOURCE_DIGITAL,
            'status' => SellerBalanceDeposit::STATUS_PENDING,
            'payment_method' => $paymentMethod,
            'metadata' => ['wallet_target' => $walletTarget],
            'idempotency_key' => 'digital-deposit:'.sha1($seller->id.'|'.microtime(true).'|'.bin2hex(random_bytes(8))),
        ]);
    }

    public function attachPaymentRequest(SellerBalanceDeposit $deposit, string $paymentRequestId): SellerBalanceDeposit
    {
        $deposit->update(['payment_request_id' => $paymentRequestId]);
        return $deposit->fresh();
    }

    public function markPaid(SellerBalanceDeposit $deposit, array $paymentData = [], ?int $adminId = null, ?string $note = null): SellerBalanceDeposit
    {
        return DB::transaction(function () use ($deposit, $paymentData, $adminId, $note) {
            $deposit = SellerBalanceDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            if ($deposit->status === SellerBalanceDeposit::STATUS_PAID) {
                return $deposit;
            }
            if (!in_array($deposit->status, [SellerBalanceDeposit::STATUS_PENDING], true)) {
                throw new DomainException('seller_balance_deposit_cannot_be_approved');
            }

            $paymentReference = $paymentData['transaction_id'] ?? $paymentData['reference'] ?? $deposit->payment_request_id;
            $walletTarget = $this->walletTarget(data_get($deposit->metadata, 'wallet_target', 'operating'));
            $bucket = $walletTarget === 'insurance'
                ? SellerLedgerService::ORDER_INSURANCE_CREDIT
                : SellerLedgerService::OPERATING;
            $eventType = $walletTarget === 'insurance'
                ? 'seller_insurance_credit_deposit'
                : 'operating_deposit';
            $this->ledger->post(
                sellerId: (int) $deposit->seller_id,
                eventType: $eventType,
                groupKey: 'seller-balance-deposit:'.$deposit->id.':credit',
                movements: [['bucket' => $bucket, 'direction' => 'credit', 'amount' => (float) $deposit->amount]],
                referenceType: SellerBalanceDeposit::class,
                referenceId: (int) $deposit->id,
                metadata: ['source' => $deposit->source, 'payment_reference' => $paymentReference],
                createdBy: $adminId,
            );

            $metadata = $deposit->metadata ?: [];
            $metadata['payment'] = array_merge($paymentData, ['paid_at' => now()->toDateTimeString()]);
            $deposit->update([
                'status' => SellerBalanceDeposit::STATUS_PAID,
                'payment_method' => $paymentData['payment_method'] ?? $deposit->payment_method,
                'transaction_reference' => $paymentReference,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => $adminId ? now() : $deposit->reviewed_at,
                'review_note' => $note ?: $deposit->review_note,
                'paid_at' => now(),
                'metadata' => $metadata,
            ]);
            return $deposit->fresh();
        });
    }

    public function reject(SellerBalanceDeposit $deposit, int $adminId, string $reason): SellerBalanceDeposit
    {
        if (trim($reason) === '') {
            throw new DomainException('seller_balance_deposit_rejection_reason_required');
        }
        $deposit = SellerBalanceDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
        if ($deposit->status !== SellerBalanceDeposit::STATUS_PENDING || $deposit->source !== SellerBalanceDeposit::SOURCE_OFFLINE) {
            throw new DomainException('seller_balance_deposit_cannot_be_rejected');
        }
        $deposit->update([
            'status' => SellerBalanceDeposit::STATUS_REJECTED,
            'rejection_reason' => trim($reason),
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => now(),
        ]);
        return $deposit->fresh();
    }

    public function reverse(SellerBalanceDeposit $deposit, int $adminId, string $reason): SellerBalanceDeposit
    {
        if (trim($reason) === '') {
            throw new DomainException('seller_balance_deposit_reversal_reason_required');
        }
        return DB::transaction(function () use ($deposit, $adminId, $reason) {
            $deposit = SellerBalanceDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            if ($deposit->status !== SellerBalanceDeposit::STATUS_PAID) {
                throw new DomainException('seller_balance_deposit_cannot_be_reversed');
            }
            $walletTarget = $this->walletTarget(data_get($deposit->metadata, 'wallet_target', 'operating'));
            $bucket = $walletTarget === 'insurance'
                ? SellerLedgerService::ORDER_INSURANCE_CREDIT
                : SellerLedgerService::OPERATING;
            $eventType = $walletTarget === 'insurance'
                ? 'seller_insurance_credit_deposit_reversal'
                : 'operating_deposit_reversal';
            $this->ledger->assertSufficient((int) $deposit->seller_id, $bucket, (float) $deposit->amount);
            $this->ledger->post(
                sellerId: (int) $deposit->seller_id,
                eventType: $eventType,
                groupKey: 'seller-balance-deposit:'.$deposit->id.':reversal',
                movements: [['bucket' => $bucket, 'direction' => 'debit', 'amount' => (float) $deposit->amount]],
                referenceType: SellerBalanceDeposit::class,
                referenceId: (int) $deposit->id,
                metadata: ['reason' => trim($reason)],
                createdBy: $adminId,
            );
            $metadata = $deposit->metadata ?: [];
            $metadata['reversal'] = ['reason' => trim($reason), 'admin_id' => $adminId, 'at' => now()->toDateTimeString()];
            $deposit->update(['status' => SellerBalanceDeposit::STATUS_REVERSED, 'reversed_at' => now(), 'reversed_by_admin_id' => $adminId, 'review_note' => trim($reason), 'metadata' => $metadata]);
            return $deposit->fresh();
        });
    }

    private function walletTarget(mixed $target): string
    {
        $target = (string) $target;
        if (! in_array($target, ['operating', 'insurance'], true)) {
            throw new DomainException('seller_balance_deposit_target_invalid');
        }
        return $target;
    }
}
