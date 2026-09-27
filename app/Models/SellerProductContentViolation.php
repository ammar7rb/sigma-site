<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerProductContentViolation extends Model
{
    protected $fillable = [
        'seller_id',
        'product_id',
        'category',
        'source',
        'detected_at',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'product_id' => 'integer',
        'detected_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
