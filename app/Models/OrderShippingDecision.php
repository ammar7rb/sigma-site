<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Admin;

class OrderShippingDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'admin_id',
        'previous_mode',
        'mode',
        'assignment_status',
        'customer_cost',
        'seller_cost',
        'seller_entitlement',
        'service_name',
        'tracking_number',
        'expected_delivery_date',
        'instructions',
        'note',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'admin_id' => 'integer',
        'customer_cost' => 'float',
        'seller_cost' => 'float',
        'seller_entitlement' => 'float',
        'expected_delivery_date' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
