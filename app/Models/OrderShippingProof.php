<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderShippingProof extends Model
{
    protected $fillable = [
        'order_id', 'seller_id', 'shipping_status', 'file_path', 'disk', 'original_name',
        'mime_type', 'sha256', 'note', 'review_status', 'reviewed_by_admin_id',
        'reviewed_at', 'review_note',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
