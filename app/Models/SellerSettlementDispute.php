<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerSettlementDispute extends Model
{
    protected $fillable = [
        'seller_settlement_id', 'order_id', 'seller_id', 'reference_type', 'reference_id',
        'amount', 'status', 'reason', 'resolved_by_admin_id', 'resolved_at', 'metadata',
    ];

    protected $casts = [
        'seller_settlement_id' => 'integer',
        'order_id' => 'integer',
        'seller_id' => 'integer',
        'reference_id' => 'integer',
        'amount' => 'float',
        'resolved_by_admin_id' => 'integer',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerSettlement::class, 'seller_settlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
