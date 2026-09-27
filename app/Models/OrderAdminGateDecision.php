<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAdminGateDecision extends Model
{
    protected $fillable = [
        'order_id', 'admin_id', 'action', 'status_before', 'status_after',
        'seller_order_insurance_id', 'shipping_decision_id', 'seller_insurance_source',
        'seller_insurance_calculation_type', 'seller_insurance_calculation_value',
        'seller_insurance_amount', 'override_reason', 'note', 'snapshot',
    ];

    protected $casts = [
        'order_id' => 'integer', 'admin_id' => 'integer',
        'seller_order_insurance_id' => 'integer', 'shipping_decision_id' => 'integer',
        'seller_insurance_calculation_value' => 'float', 'seller_insurance_amount' => 'float',
        'snapshot' => 'array',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function admin(): BelongsTo { return $this->belongsTo(Admin::class, 'admin_id'); }
}
