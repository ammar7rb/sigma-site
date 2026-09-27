<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPurchasePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'name',
        'description',
        'package_price',
        'purchase_limit',
        'is_custom',
        'status',
        'sort_order',
        'duration_unit',
        'duration_value',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'customer_id' => 'integer',
        'package_price' => 'float',
        'purchase_limit' => 'float',
        'is_custom' => 'boolean',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'duration_value' => 'integer',
        'created_by_admin_id' => 'integer',
        'updated_by_admin_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CustomerPurchasePackageSubscription::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerPurchaseLimitTransaction::class);
    }
}
