<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerProductEntitlement extends Model
{
    use HasFactory;

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RESTORED = 'restored';

    protected $fillable = [
        'seller_id',
        'product_id',
        'seller_package_subscription_id',
        'seller_package_id',
        'duration_days',
        'status',
        'quota_restored',
        'starts_at',
        'expires_at',
        'activated_at',
        'expired_at',
        'cancelled_at',
        'quota_restored_at',
        'metadata',
    ];

    protected $casts = [
        'id' => 'integer',
        'seller_id' => 'integer',
        'product_id' => 'integer',
        'seller_package_subscription_id' => 'integer',
        'seller_package_id' => 'integer',
        'duration_days' => 'integer',
        'quota_restored' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'expired_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'quota_restored_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SellerPackageSubscription::class, 'seller_package_subscription_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SellerPackage::class, 'seller_package_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(SellerProductPromotion::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SellerPackageTransaction::class);
    }
}
