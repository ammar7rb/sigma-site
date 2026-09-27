<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerPackageSubscription extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REPLACED = 'replaced';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    protected $appends = ['remaining_days', 'duration_status', 'duration_label'];

    protected $fillable = [
        'seller_id',
        'seller_package_id',
        'package_name',
        'paid_package_price',
        'product_limit',
        'used_product_limit',
        'product_adjustment_limit',
        'product_duration_days',
        'search_promotion_limit',
        'used_search_promotion_limit',
        'search_promotion_adjustment_limit',
        'search_promotion_duration_days',
        'homepage_promotion_limit',
        'used_homepage_promotion_limit',
        'homepage_promotion_adjustment_limit',
        'homepage_promotion_duration_days',
        'coupon_limit',
        'used_coupon_limit',
        'coupon_adjustment_limit',
        'package_validity_days',
        'duration_unit',
        'duration_value',
        'status',
        'payment_status',
        'payment_method',
        'payment_reference',
        'payment_request_id',
        'starts_at',
        'started_at',
        'expires_at',
        'activated_at',
        'cancelled_at',
        'search_priority',
        'cancellation_effect',
        'cancel_at_period_end',
        'cancellation_requested_at',
        'cancellation_reason',
        'cancellation_requested_by_type',
        'cancellation_requested_by_id',
        'metadata',
    ];

    protected $casts = [
        'id' => 'integer',
        'seller_id' => 'integer',
        'seller_package_id' => 'integer',
        'paid_package_price' => 'float',
        'product_limit' => 'integer',
        'used_product_limit' => 'integer',
        'product_adjustment_limit' => 'integer',
        'product_duration_days' => 'integer',
        'search_promotion_limit' => 'integer',
        'used_search_promotion_limit' => 'integer',
        'search_promotion_adjustment_limit' => 'integer',
        'search_promotion_duration_days' => 'integer',
        'homepage_promotion_limit' => 'integer',
        'used_homepage_promotion_limit' => 'integer',
        'homepage_promotion_adjustment_limit' => 'integer',
        'homepage_promotion_duration_days' => 'integer',
        'coupon_limit' => 'integer',
        'used_coupon_limit' => 'integer',
        'coupon_adjustment_limit' => 'integer',
        'package_validity_days' => 'integer',
        'duration_value' => 'integer',
        'starts_at' => 'datetime',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'search_priority' => 'integer',
        'cancel_at_period_end' => 'boolean',
        'cancellation_requested_at' => 'datetime',
        'cancellation_requested_by_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SellerPackage::class, 'seller_package_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(SellerProductEntitlement::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(SellerProductPromotion::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SellerPackageTransaction::class);
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(SellerPackageProductMetric::class);
    }

    public function getRemainingDaysAttribute(): ?int
    {
        return $this->expires_at ? max(0, now()->diffInDays($this->expires_at, false)) : null;
    }

    public function getDurationStatusAttribute(): string
    {
        $unit = $this->duration_unit ?: ($this->package_validity_days ? 'days' : 'lifetime');
        return $unit === 'lifetime' ? 'lifetime' : ($this->expires_at?->lte(now()) ? 'expired' : 'active');
    }

    public function getDurationLabelAttribute(): string
    {
        $unit = $this->duration_unit ?: ($this->package_validity_days ? 'days' : 'lifetime');
        $value = $this->duration_value ?: $this->package_validity_days;
        return $unit === 'lifetime' ? 'lifetime' : sprintf('%d_%s', (int) $value, $unit);
    }
}
