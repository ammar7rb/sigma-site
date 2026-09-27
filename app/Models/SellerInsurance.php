<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerInsurance extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PAID = 'paid';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_FORFEITED = 'forfeited';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'seller_id',
        'transaction_id',
        'amount',
        'status',
        'payment_status',
        'payment_method',
        'payment_reference',
        'payment_request_id',
        'forfeiture_reason',
        'created_by_admin_id',
        'reviewed_by_admin_id',
        'paid_at',
        'waived_at',
        'forfeited_at',
        'reviewed_at',
        'metadata',
    ];

    protected $casts = [
        'id' => 'integer',
        'seller_id' => 'integer',
        'amount' => 'float',
        'created_by_admin_id' => 'integer',
        'reviewed_by_admin_id' => 'integer',
        'paid_at' => 'datetime',
        'waived_at' => 'datetime',
        'forfeited_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }
}
