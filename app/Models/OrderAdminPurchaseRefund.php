<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAdminPurchaseRefund extends Model
{
    protected $fillable = ['order_id', 'customer_id', 'amount', 'status', 'due_at', 'completed_at', 'reference', 'reason', 'admin_id'];
    protected $casts = ['order_id' => 'integer', 'customer_id' => 'integer', 'amount' => 'float', 'admin_id' => 'integer', 'due_at' => 'datetime', 'completed_at' => 'datetime'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
