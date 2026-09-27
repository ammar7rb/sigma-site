<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLogisticsEvent extends Model
{
    protected $fillable = [
        'order_id', 'event_type', 'previous_order_status', 'order_status', 'blocked_by', 'reason_code',
        'event_key', 'shipping_status', 'responsible_party', 'actor_type', 'actor_id',
        'customer_shipping_cost', 'seller_shipping_cost', 'return_shipping_cost', 'tracking_number',
        'note', 'metadata',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'actor_id' => 'integer',
        'customer_shipping_cost' => 'float',
        'seller_shipping_cost' => 'float',
        'return_shipping_cost' => 'float',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
