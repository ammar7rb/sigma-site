<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class SellerBalanceDeposit extends Model
{
    public const SOURCE_OFFLINE = 'offline';
    public const SOURCE_DIGITAL = 'digital';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'seller_id', 'amount', 'currency_code', 'source', 'status',
        'payment_request_id', 'payment_method', 'transaction_reference',
        'offline_payment_method_id', 'offline_information', 'payment_proof',
        'payment_note', 'review_note', 'rejection_reason', 'metadata',
        'reviewed_by_admin_id', 'reviewed_at', 'paid_at', 'reversed_at',
        'reversed_by_admin_id', 'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'float',
        'offline_information' => 'array',
        'payment_proof' => 'array',
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
        'seller_id' => 'integer',
        'reviewed_by_admin_id' => 'integer',
        'reversed_by_admin_id' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function offlinePaymentMethod(): BelongsTo
    {
        return $this->belongsTo(OfflinePaymentMethod::class, 'offline_payment_method_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }
}
