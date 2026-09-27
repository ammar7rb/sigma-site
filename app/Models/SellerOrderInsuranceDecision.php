<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerOrderInsuranceDecision extends Model
{
    protected $fillable = [
        'seller_order_insurance_id', 'order_id', 'seller_id', 'admin_id',
        'action', 'status_before', 'status_after', 'reason', 'payload',
    ];

    protected $casts = ['payload' => 'array'];

    public function insurance(): BelongsTo
    {
        return $this->belongsTo(SellerOrderInsurance::class, 'seller_order_insurance_id');
    }
}
