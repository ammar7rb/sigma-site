<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerActivationInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_no',
        'customer_id',
        'order_id',
        'order_group_id',
        'customer_purchase_package_id',
        'customer_purchase_package_subscription_id',
        'payment_request_id',
        'package_name',
        'package_price',
        'package_purchase_limit',
        'insurance_original_amount',
        'insurance_discount_amount',
        'insurance_discount_type',
        'insurance_amount',
        'total_amount',
        'paid_amount',
        'currency_code',
        'payment_method',
        'payment_reference',
        'payment_status',
        'status',
        'insurance_period_start',
        'insurance_period_end',
        'paid_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'id' => 'integer',
        'customer_id' => 'integer',
        'order_id' => 'integer',
        'customer_purchase_package_id' => 'integer',
        'customer_purchase_package_subscription_id' => 'integer',
        'package_price' => 'float',
        'package_purchase_limit' => 'float',
        'insurance_original_amount' => 'float',
        'insurance_discount_amount' => 'float',
        'insurance_amount' => 'float',
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'insurance_period_start' => 'datetime',
        'insurance_period_end' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchasePackage::class, 'customer_purchase_package_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchasePackageSubscription::class, 'customer_purchase_package_subscription_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }
}
