<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerStockViolation extends Model
{
    public const DECISION_WARNING = 'warning';
    public const DECISION_PENALTY = 'penalty';

    protected $fillable = [
        'seller_id', 'product_id', 'order_id', 'alert_type', 'decision', 'policy_reference',
        'reason', 'penalty_amount', 'decided_by', 'detected_at', 'decided_at',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'product_id' => 'integer',
        'order_id' => 'integer',
        'penalty_amount' => 'decimal:2',
        'decided_by' => 'integer',
        'detected_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by');
    }
}
