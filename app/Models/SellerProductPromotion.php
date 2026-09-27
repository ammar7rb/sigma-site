<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerProductPromotion extends Model
{
    use HasFactory;

    public const TYPE_SEARCH = 'search';
    public const TYPE_HOMEPAGE = 'homepage';

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RESTORED = 'restored';

    public const APPROVAL_PENDING = 'pending_admin_review';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';
    public const APPROVAL_LEGACY = 'legacy_auto_approved';

    protected $fillable = [
        'seller_id',
        'product_id',
        'seller_package_subscription_id',
        'seller_product_entitlement_id',
        'promotion_type',
        'duration_days',
        'sort_order',
        'search_priority',
        'status',
        'approval_status',
        'requested_at',
        'reviewed_by_admin_id',
        'reviewed_at',
        'review_note',
        'placement_scope',
        'placement_key',
        'requested_position',
        'approved_position',
        'quota_restored',
        'starts_at',
        'expires_at',
        'activated_at',
        'expired_at',
        'cancelled_at',
        'quota_restored_at',
        'created_by_admin_id',
        'metadata',
    ];

    protected $casts = [
        'id' => 'integer',
        'seller_id' => 'integer',
        'product_id' => 'integer',
        'seller_package_subscription_id' => 'integer',
        'seller_product_entitlement_id' => 'integer',
        'duration_days' => 'integer',
        'sort_order' => 'integer',
        'search_priority' => 'integer',
        'requested_at' => 'datetime',
        'reviewed_by_admin_id' => 'integer',
        'reviewed_at' => 'datetime',
        'requested_position' => 'integer',
        'approved_position' => 'integer',
        'quota_restored' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'expired_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'quota_restored_at' => 'datetime',
        'created_by_admin_id' => 'integer',
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

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(SellerProductEntitlement::class, 'seller_product_entitlement_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SellerPackageTransaction::class);
    }
}
