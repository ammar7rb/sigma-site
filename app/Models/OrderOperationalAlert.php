<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderOperationalAlert extends Model
{
    protected $fillable = [
        'order_id', 'seller_id', 'alert_type', 'level', 'order_status', 'due_at',
        'notified_at', 'resolved_at', 'idempotency_key', 'metadata',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'seller_id' => 'integer',
        'due_at' => 'datetime',
        'notified_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
