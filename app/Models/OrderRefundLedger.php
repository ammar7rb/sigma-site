<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRefundLedger extends Model
{
    protected $fillable = [
        'order_id', 'customer_id', 'idempotency_key', 'reason', 'purchase_amount',
        'insurance_amount', 'purchase_available_at', 'insurance_available_at',
        'status', 'admin_id', 'admin_note', 'metadata',
    ];

    protected $casts = [
        'purchase_amount' => 'float', 'insurance_amount' => 'float',
        'purchase_available_at' => 'datetime', 'insurance_available_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
