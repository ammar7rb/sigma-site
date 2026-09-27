<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'package_price',
        'product_limit',
        'product_duration_days',
        'search_promotion_limit',
        'search_promotion_duration_days',
        'homepage_promotion_limit',
        'homepage_promotion_duration_days',
        'coupon_limit',
        'package_validity_days',
        'duration_unit',
        'duration_value',
        'status',
        'sort_order',
        'search_priority',
        'cancellation_effect',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'package_price' => 'float',
        'product_limit' => 'integer',
        'product_duration_days' => 'integer',
        'search_promotion_limit' => 'integer',
        'search_promotion_duration_days' => 'integer',
        'homepage_promotion_limit' => 'integer',
        'homepage_promotion_duration_days' => 'integer',
        'coupon_limit' => 'integer',
        'package_validity_days' => 'integer',
        'duration_value' => 'integer',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'search_priority' => 'integer',
        'created_by_admin_id' => 'integer',
        'updated_by_admin_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SellerPackageSubscription::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SellerPackageTransaction::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }
}
