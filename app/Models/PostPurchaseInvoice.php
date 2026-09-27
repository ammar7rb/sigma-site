<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostPurchaseInvoice extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_REVIEW = 'awaiting_review';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id', 'customer_id', 'contract_version', 'status', 'taxable_amount',
        'tax_rate', 'tax_amount', 'insurance_amount', 'total_amount', 'paid_amount',
        'payment_due_at', 'paid_at', 'payment_method', 'payment_reference',
        'tax_snapshot', 'insurance_snapshot', 'metadata',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'customer_id' => 'integer',
        'taxable_amount' => 'float',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'insurance_amount' => 'float',
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'payment_due_at' => 'datetime',
        'paid_at' => 'datetime',
        'tax_snapshot' => 'array',
        'insurance_snapshot' => 'array',
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

    public function isPayable(): bool
    {
        // An offline proof is locked while the administrator reviews it.
        // It must not be possible to add a digital or insurance-wallet
        // payment on top of that proof.
        return $this->status === self::STATUS_PENDING
            && (float) $this->total_amount > (float) $this->paid_amount;
    }
}
