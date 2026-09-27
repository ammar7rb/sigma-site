<?php

namespace App\Services;

use App\Models\CustomerBalanceDeposit;
use App\Models\CustomerInsuranceLedgerEntry;
use App\Models\User;
use App\Models\WalletTransaction;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerBalanceDepositService
{
    public function receiptResponse(CustomerBalanceDeposit $deposit): \Illuminate\Http\Response
    {
        $bytes = \Illuminate\Support\Facades\Crypt::decryptString(\Illuminate\Support\Facades\Storage::disk('local')->get($deposit->payment_proof));
        return response($bytes, 200, [
            'Content-Type' => getimagesizefromstring($bytes)['mime'] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function review(CustomerBalanceDeposit $deposit, string $decision, int $adminId, ?string $note): CustomerBalanceDeposit
    {
        if (!in_array($decision, ['approve', 'reject'], true) || $adminId <= 0) {
            throw new DomainException('invalid_deposit_decision');
        }
        return DB::transaction(function () use ($deposit, $decision, $adminId, $note) {
            $deposit = CustomerBalanceDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            if ($deposit->status !== 'pending') {
                throw new DomainException('deposit_already_reviewed');
            }
            if ($decision === 'reject' && !trim((string) $note)) {
                throw new DomainException('deposit_rejection_note_required');
            }
            if ($decision === 'approve') {
                $customer = User::query()->lockForUpdate()->findOrFail($deposit->customer_id);
                if (!is_finite($deposit->amount) || $deposit->amount <= 0) throw new DomainException('invalid_deposit_amount');
                $reference = 'customer-offline-deposit-' . $deposit->id;
                if ($deposit->wallet_type === 'insurance') {
                    CustomerInsuranceLedgerEntry::query()->create([
                        'customer_id' => $customer->id, 'entry_type' => 'deposit',
                        'credit' => $deposit->amount, 'debit' => 0, 'reference' => $reference,
                        'metadata' => ['restricted_to' => 'insurance_payment', 'deposit_id' => $deposit->id, 'admin_id' => $adminId, 'payment_method' => 'offline'],
                    ]);
                } elseif ($deposit->wallet_type === 'purchase') {
                    // Amounts are stored in the same base currency as wallet_balance.
                    $customer->wallet_balance = (float) $customer->wallet_balance + $deposit->amount;
                    $customer->save();
                    WalletTransaction::query()->create([
                        'user_id' => $customer->id, 'transaction_id' => (string) Str::uuid(),
                        'transaction_type' => 'add_fund', 'reference' => $reference, 'payment_method' => 'offline',
                        'credit' => $deposit->amount, 'debit' => 0, 'admin_bonus' => 0, 'balance' => $customer->wallet_balance,
                    ]);
                } else {
                    throw new DomainException('invalid_deposit_wallet');
                }
            }
            $deposit->update(['status' => $decision === 'approve' ? 'paid' : 'rejected', 'reviewed_by_admin_id' => $adminId, 'review_note' => $note, 'reviewed_at' => now()]);
            return $deposit;
        });
    }
}
