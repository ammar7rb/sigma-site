<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPurchaseLimitTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'customer_purchase_package_subscription_id',
        'customer_purchase_package_id',
        'order_id',
        'payment_request_id',
        'transaction_id',
        'transaction_type',
        'credit',
        'debit',
        'balance_after',
        'product_amount',
        'paid_amount',
        'reference',
        'note',
        'created_by_admin_id',
        'metadata',
    ];

    protected $casts = [
        'id' => 'integer',
        'customer_id' => 'integer',
        'customer_purchase_package_subscription_id' => 'integer',
        'customer_purchase_package_id' => 'integer',
        'order_id' => 'integer',
        'credit' => 'float',
        'debit' => 'float',
        'balance_after' => 'float',
        'product_amount' => 'float',
        'paid_amount' => 'float',
        'created_by_admin_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchasePackageSubscription::class, 'customer_purchase_package_subscription_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchasePackage::class, 'customer_purchase_package_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }
}
