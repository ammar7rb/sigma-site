<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderInsurance extends Model
{
    protected $fillable = [
        'order_id', 'customer_id', 'insurance_rule_id', 'insurance_incentive_id',
        'amount', 'original_amount', 'discount_amount', 'order_amount', 'threshold_amount',
        'calculation_type', 'calculation_value', 'payment_method', 'payment_status',
        'payment_due_at', 'payment_completed_at', 'support_ticket_id', 'balance_use_policy',
        'purchase_refund_status', 'purchase_refund_due_at', 'purchase_refund_completed_at', 'purchase_refund_reference',
        'confiscation_status', 'confiscated_amount', 'confiscated_by_admin_id', 'confiscated_at', 'confiscation_reason',
        'status', 'admin_id', 'admin_note', 'refunded_at', 'maturity_days', 'matures_at', 'matured_at', 'metadata', 'rule_snapshot',
    ];

    protected $casts = [
        'amount' => 'float', 'original_amount' => 'float', 'discount_amount' => 'float',
        'order_amount' => 'float', 'threshold_amount' => 'float',
        'calculation_value' => 'float', 'refunded_at' => 'datetime', 'maturity_days' => 'integer',
        'payment_due_at' => 'datetime', 'payment_completed_at' => 'datetime', 'support_ticket_id' => 'integer',
        'purchase_refund_due_at' => 'datetime', 'purchase_refund_completed_at' => 'datetime',
        'confiscated_amount' => 'float', 'confiscated_by_admin_id' => 'integer', 'confiscated_at' => 'datetime',
        'matures_at' => 'datetime', 'matured_at' => 'datetime', 'metadata' => 'array', 'rule_snapshot' => 'array',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
}
