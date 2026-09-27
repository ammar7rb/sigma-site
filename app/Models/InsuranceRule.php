<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceRule extends Model
{
    protected $fillable = [
        'subject_type', 'name', 'code', 'rule_type', 'priority', 'is_active',
        'minimum_order_amount', 'maximum_order_amount', 'minimum_account_age_days',
        'maximum_account_age_days', 'calculation_type', 'calculation_value',
        'starts_at', 'ends_at', 'metadata',
    ];

    protected $casts = [
        'priority' => 'integer', 'is_active' => 'boolean',
        'minimum_order_amount' => 'float', 'maximum_order_amount' => 'float',
        'minimum_account_age_days' => 'integer', 'maximum_account_age_days' => 'integer',
        'calculation_value' => 'float', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'metadata' => 'array',
    ];
}
