<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceIncentiveRedemption extends Model
{
    protected $fillable = [
        'insurance_incentive_id', 'customer_id', 'order_insurance_id',
        'redemption_type', 'amount', 'reference', 'metadata',
    ];

    protected $casts = ['amount' => 'float', 'metadata' => 'array'];
}
