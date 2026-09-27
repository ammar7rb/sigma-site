<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class SellerSettlement extends Model
{
    public const STATUS_HELD = 'held';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'seller_id',
        'order_id',
        'amount',
        'status',
        'delivered_at',
        'dispute_until',
        'due_at',
        'timezone_snapshot',
        'pre_due_days_snapshot',
        'pre_due_notified_at',
        'overdue_notified_at',
        'escalated_at',
        'released_at',
        'reversed_at',
        'reviewed_by_admin_id',
        'review_note',
        'metadata',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'order_id' => 'integer',
        'amount' => 'float',
        'delivered_at' => 'datetime',
        'dispute_until' => 'datetime',
        'due_at' => 'datetime',
        'pre_due_days_snapshot' => 'integer',
        'pre_due_notified_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
        'escalated_at' => 'datetime',
        'released_at' => 'datetime',
        'reversed_at' => 'datetime',
        'reviewed_by_admin_id' => 'integer',
        'metadata' => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
