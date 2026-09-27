<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerProductReviewDecision extends Model
{
    protected $fillable = [
        'product_id',
        'seller_id',
        'status',
        'reason',
        'changed_fields',
        'admin_id',
        'decided_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'seller_id' => 'integer',
        'admin_id' => 'integer',
        'decided_at' => 'datetime',
        'changed_fields' => 'array',
    ];
}
