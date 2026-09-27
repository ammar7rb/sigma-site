<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPackageProductMetric extends Model
{
    protected $fillable = [
        'seller_id',
        'seller_package_subscription_id',
        'product_id',
        'metric_date',
        'impressions',
        'visits',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'seller_package_subscription_id' => 'integer',
        'product_id' => 'integer',
        'metric_date' => 'date',
        'impressions' => 'integer',
        'visits' => 'integer',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SellerPackageSubscription::class, 'seller_package_subscription_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
