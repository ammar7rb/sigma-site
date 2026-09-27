<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerOrderInsurance extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'order_id', 'seller_id', 'insurance_rule_id', 'amount', 'amount_source', 'admin_override_type', 'admin_override_value',
        'admin_decided_by', 'admin_decided_at', 'admin_decision_reason', 'balance_use_policy',
        'confiscation_status', 'confiscated_amount', 'confiscated_by_admin_id', 'confiscated_at', 'confiscation_reason',
        'order_amount', 'percentage',
        'calculation_type', 'calculation_value', 'status', 'payment_status',
        'payment_method', 'payment_reference', 'payment_request_id', 'pending_days', 'reuse_after_days', 'expires_at', 'reusable_at',
        'paid_at', 'reusable_released_at', 'expired_at', 'reviewed_by_admin_id', 'admin_note', 'metadata', 'rule_snapshot',
    ];

    protected $casts = [
        'amount' => 'float', 'admin_override_value' => 'float', 'admin_decided_by' => 'integer', 'admin_decided_at' => 'datetime',
        'confiscated_amount' => 'float', 'confiscated_by_admin_id' => 'integer', 'confiscated_at' => 'datetime',
        'order_amount' => 'float', 'percentage' => 'float', 'calculation_value' => 'float', 'pending_days' => 'integer',
        'expires_at' => 'datetime', 'reusable_at' => 'datetime', 'paid_at' => 'datetime', 'reusable_released_at' => 'datetime', 'expired_at' => 'datetime', 'metadata' => 'array', 'rule_snapshot' => 'array',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
    public function decisions(): HasMany { return $this->hasMany(SellerOrderInsuranceDecision::class)->latest('id'); }
}
