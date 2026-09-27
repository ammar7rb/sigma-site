<?php

namespace App\Services;

use App\Models\VendorWithdrawMethodInfo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use DomainException;

/**
 * Business rules for seller withdrawal destinations.  A destination is never
 * usable until an administrator approves it.
 */
class SellerWithdrawalMethodService
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public const BANK = 'bank';
    public const INSTAPAY = 'instapay';
    public const VODAFONE_CASH = 'vodafone_cash';

    public function resolveMethodType(?string $requested, ?string $name = null): string
    {
        $value = mb_strtolower(trim((string) ($requested ?: $name)), 'UTF-8');
        if ($value === '' || str_contains($value, 'bank') || str_contains($value, 'بنك')) {
            return self::BANK;
        }
        if (str_contains($value, 'insta') || str_contains($value, 'إنستا') || str_contains($value, 'انستا')) {
            return self::INSTAPAY;
        }
        if (str_contains($value, 'vodafone') || str_contains($value, 'فودافون')) {
            return self::VODAFONE_CASH;
        }
        throw ValidationException::withMessages(['method_type' => __('new-messages.unsupported_withdrawal_method')]);
    }

    public function prepareSubmission(array $data, ?string $requestedType = null): array
    {
        $data['method_type'] = $this->resolveMethodType($requestedType, $data['method_name'] ?? null);
        $data['approval_status'] = self::PENDING;
        $data['approval_reason'] = null;
        $data['submitted_at'] = now();
        $data['reviewed_at'] = null;
        $data['reviewed_by'] = null;
        $data['is_active'] = false;
        $data['is_default'] = false;
        return $data;
    }

    public function approve(VendorWithdrawMethodInfo $method, int|string $adminId): VendorWithdrawMethodInfo
    {
        return DB::transaction(function () use ($method, $adminId) {
            $method->forceFill([
                'approval_status' => self::APPROVED,
                'approval_reason' => null,
                'reviewed_at' => now(),
                'reviewed_by' => $adminId,
                'is_active' => true,
            ])->save();

            $hasDefault = VendorWithdrawMethodInfo::query()
                ->where('user_id', $method->user_id)
                ->where('approval_status', self::APPROVED)
                ->where('is_active', true)
                ->where('is_default', true)
                ->where($method->getKeyName(), '<>', $method->id)
                ->exists();
            if (!$hasDefault) {
                $method->forceFill(['is_default' => true])->save();
            }
            return $method->fresh(['withdraw_method', 'seller']);
        });
    }

    public function reject(VendorWithdrawMethodInfo $method, int|string $adminId, string $reason): VendorWithdrawMethodInfo
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('new-messages.withdrawal_rejection_reason_required')]);
        }
        $method->forceFill([
            'approval_status' => self::REJECTED,
            'approval_reason' => trim($reason),
            'reviewed_at' => now(),
            'reviewed_by' => $adminId,
            'is_active' => false,
            'is_default' => false,
        ])->save();
        return $method->fresh(['withdraw_method', 'seller']);
    }

    public function assertUsable(int|string $id, int|string $sellerId): VendorWithdrawMethodInfo
    {
        $method = VendorWithdrawMethodInfo::query()->whereKey($id)->where('user_id', $sellerId)->first();
        if (!$method) {
            throw (new ModelNotFoundException())->setModel(VendorWithdrawMethodInfo::class, [$id]);
        }
        if ($method->approval_status !== self::APPROVED || !$method->is_active) {
            throw new DomainException(__('new-messages.withdrawal_method_not_approved'));
        }
        return $method;
    }

    public function setDefault(int|string $id, int|string $sellerId): VendorWithdrawMethodInfo
    {
        $method = $this->assertUsable($id, $sellerId);
        return DB::transaction(function () use ($method, $sellerId) {
            VendorWithdrawMethodInfo::query()->where('user_id', $sellerId)->update(['is_default' => false]);
            $method->forceFill(['is_default' => true, 'is_active' => true])->save();
            return $method->fresh(['withdraw_method']);
        });
    }

    public function setActive(int|string $id, int|string $sellerId, bool $active): VendorWithdrawMethodInfo
    {
        $method = VendorWithdrawMethodInfo::query()->whereKey($id)->where('user_id', $sellerId)->firstOrFail();
        if ($active) {
            $this->assertUsable($id, $sellerId);
        }
        $method->forceFill(['is_active' => $active, 'is_default' => $active ? $method->is_default : false])->save();
        return $method;
    }
}
