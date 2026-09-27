<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPackageTransaction extends Model
{
    use HasFactory;

    public const TYPE_PACKAGE_PURCHASE = 'package_purchase';

    public const TYPE_QUOTA_GRANT = 'quota_grant';

    public const TYPE_QUOTA_USAGE = 'quota_usage';

    public const TYPE_QUOTA_RESTORE = 'quota_restore';

    public const TYPE_QUOTA_ADJUSTMENT = 'quota_adjustment';

    public const TYPE_PROMOTION_ADMIN_UPDATE = 'promotion_admin_update';

    public const QUOTA_PRODUCT = 'product';

    public const QUOTA_SEARCH_PROMOTION = 'search_promotion';

    public const QUOTA_HOMEPAGE_PROMOTION = 'homepage_promotion';

    public const QUOTA_COUPON = 'coupon';

    protected $fillable = [
        'seller_id',
        'seller_package_subscription_id',
        'seller_package_id',
        'product_id',
        'seller_product_entitlement_id',
        'seller_product_promotion_id',
        'payment_request_id',
        'transaction_id',
        'transaction_type',
        'quota_type',
        'credit',
        'debit',
        'balance_after',
        'paid_amount',
        'reference',
        'note',
        'created_by_admin_id',
        'metadata',
    ];

    protected $casts = [
        'id' => 'integer',
        'seller_id' => 'integer',
        'seller_package_subscription_id' => 'integer',
        'seller_package_id' => 'integer',
        'product_id' => 'integer',
        'seller_product_entitlement_id' => 'integer',
        'seller_product_promotion_id' => 'integer',
        'credit' => 'integer',
        'debit' => 'integer',
        'balance_after' => 'integer',
        'paid_amount' => 'float',
        'created_by_admin_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SellerPackageSubscription::class, 'seller_package_subscription_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SellerPackage::class, 'seller_package_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(SellerProductEntitlement::class, 'seller_product_entitlement_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(SellerProductPromotion::class, 'seller_product_promotion_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
