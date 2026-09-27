<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceIncentive extends Model
{
    protected $fillable = [
        'name', 'code', 'incentive_type', 'value_type', 'value', 'maximum_amount',
        'priority', 'is_active', 'new_accounts_only', 'minimum_account_age_days',
        'maximum_account_age_days', 'usage_limit_per_customer', 'starts_at', 'ends_at', 'metadata',
    ];

    protected $casts = [
        'value' => 'float', 'maximum_amount' => 'float', 'priority' => 'integer',
        'is_active' => 'boolean', 'new_accounts_only' => 'boolean',
        'minimum_account_age_days' => 'integer', 'maximum_account_age_days' => 'integer',
        'usage_limit_per_customer' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'metadata' => 'array',
    ];
}
