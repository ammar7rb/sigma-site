<?php

namespace App\Models;

use App\Traits\DemoMaskingTrait;
use Carbon\Carbon;
use DateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $customer_id
 * @property bool $is_guest
 * @property string $customer_type
 * @property string $payment_status
 * @property string $order_status
 * @property string $payment_method
 * @property string $transaction_ref
 * @property string $payment_by
 * @property string $payment_note
 * @property float $order_amount
 * @property float $init_order_amount
 * @property float $total_tax_amount
 * @property string $tax_type
 * @property string $tax_model
 * @property float $paid_amount
 * @property float $bring_change_amount
 * @property string $bring_change_amount_currency
 * @property float $admin_commission
 * @property bool $is_pause
 * @property string $cause
 * @property string $shipping_address
 * @property DateTime $created_at
 * @property DateTime $updated_at
 * @property float $discount_amount
 * @property string $discount_type
 * @property string $coupon_code
 * @property string $coupon_discount_bearer
 * @property string $shipping_responsibility
 * @property int $shipping_method_id
 * @property float $shipping_cost
 * @property bool $is_shipping_free
 * @property string $order_group_id
 * @property string $verification_code
 * @property bool $verification_status
 * @property int $seller_id
 * @property string $seller_is
 * @property object $shipping_address_data
 * @property int $delivery_man_id
 * @property Carbon|null $deliveryman_assigned_at
 * @property float $deliveryman_charge
 * @property DateTime $expected_delivery_date
 * @property string $order_note
 * @property int $billing_address
 * @property object $billing_address_data
 * @property string $order_type
 * @property float $extra_discount
 * @property string $extra_discount_type
 * @property float $refer_and_earn_discount
 * @property string $free_delivery_bearer
 * @property bool $checked
 * @property string $shipping_type
 * @property string $delivery_type
 * @property string $delivery_service_name
 * @property string $third_party_delivery_tracking_id
 */
class Order extends Model
{
    use DemoMaskingTrait;

    protected $fillable = [
        'id',
        'customer_id',
        'is_guest',
        'customer_type',
        'payment_status',
        'order_status',
        'commerce_flow_version',
        'commerce_flow_status',
        'commerce_flow_status_updated_at',
        'admin_order_review_status',
        'admin_order_reviewed_by',
        'admin_order_reviewed_at',
        'admin_order_review_note',
        'operational_blocked_by',
        'operational_block_reason',
        'operational_status_updated_at',
        'seller_queue_priority',
        'seller_queue_position',
        'seller_queue_override_at',
        'seller_queue_override_by',
        'seller_queue_override_reason',
        'activation_status',
        'activation_pending_at',
        'activation_completed_at',
        'payment_method',
        'transaction_ref',
        'payment_by',
        'payment_note',
        'order_amount',
        'init_order_amount',
        'edit_due_amount',
        'edit_return_amount',
        'total_tax_amount',
        'tax_type',
        'tax_model',
        'paid_amount',
        'bring_change_amount',
        'bring_change_amount_currency',
        'admin_commission',
        'is_pause',
        'cause',
        'shipping_address',
        'discount_type',
        'discount_amount',
        'coupon_code',
        'coupon_discount_bearer',
        'shipping_responsibility',
        'shipping_fulfillment_mode',
        'shipping_assignment_status',
        'shipping_operational_status',
        'shipping_responsible_party',
        'return_responsible_party',
        'shipping_method_id',
        'shipping_cost',
        'post_purchase_status',
        'shipping_settlement_due_at',
        'shipping_eta_from',
        'shipping_eta_to',
        'shipping_duration_snapshot',
        'shipping_promise_key',
        'sales_settlement_due_at',
        'shipping_due_separated',
        'shipping_due_override_reason',
        'seller_shipping_allocation',
        'platform_shipping_margin',
        'shipping_workflow_status',
        'shipping_customer_cost',
        'shipping_seller_cost',
        'shipping_seller_entitlement',
        'return_shipping_cost',
        'shipping_decided_at',
        'shipping_decided_by',
        'shipping_decision_note',
        'seller_shipping_response_status',
        'seller_shipping_response_due_at',
        'seller_shipping_response_at',
        'seller_shipping_rejection_reason',
        'seller_shipping_support_ticket_id',
        'shipping_quantity',
        'shipping_product_subtotal',
        'shipping_quantity_surcharge',
        'is_shipping_free',
        'order_group_id',
        'verification_code',
        'verification_status',
        'seller_id',
        'seller_is',
        'shipping_address_data',
        'delivery_man_id',
        'deliveryman_assigned_at',
        'deliveryman_charge',
        'expected_delivery_date',
        'order_note',
        'billing_address',
        'billing_address_data',
        'order_type',
        'extra_discount',
        'extra_discount_type',
        'refer_and_earn_discount',
        'free_delivery_bearer',
        'checked',
        'shipping_type',
        'delivery_type',
        'delivery_service_name',
        'third_party_delivery_tracking_id',
        'shipment_reference',
        'pickup_address_snapshot',
        'customer_address_snapshot',
        'shipping_price_snapshot',
        'return_tracking_number',
        'return_reason',
        'return_started_at',
        'return_completed_at',
        'customer_delivery_confirmation_status',
        'customer_delivery_confirmation_due_at',
        'customer_delivery_confirmed_at',
        'customer_delivery_confirmation_expired_at',
        'edited_status',
        'updated_at'
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'is_guest' => 'boolean',
        'customer_type' => 'string',
        'payment_status' => 'string',
        'order_status' => 'string',
        'commerce_flow_version' => 'string',
        'commerce_flow_status' => 'string',
        'commerce_flow_status_updated_at' => 'datetime',
        'admin_order_review_status' => 'string',
        'admin_order_reviewed_by' => 'integer',
        'admin_order_reviewed_at' => 'datetime',
        'operational_status_updated_at' => 'datetime',
        'seller_queue_priority' => 'integer',
        'seller_queue_position' => 'integer',
        'seller_queue_override_at' => 'datetime',
        'seller_queue_override_by' => 'integer',
        'activation_status' => 'string',
        'activation_pending_at' => 'datetime',
        'activation_completed_at' => 'datetime',
        'payment_method' => 'string',
        'transaction_ref' => 'string',
        'payment_by' => 'string',
        'payment_note' => 'string',
        'order_amount' => 'float',
        'init_order_amount' => 'float',
        'total_tax_amount' => 'float',
        'tax_type' => 'string',
        'tax_model' => 'string',
        'refer_and_earn_discount' => 'float',
        'paid_amount' => 'float',
        'bring_change_amount' => 'float',
        'bring_change_amount_currency' => 'string',
        'admin_commission' => 'float',
        'is_pause' => 'boolean',
        'cause' => 'string',
        'shipping_address' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'discount_amount' => 'float',
        'discount_type' => 'string',
        'coupon_code' => 'string',
        'coupon_discount_bearer' => 'string',
        'shipping_responsibility' => 'string',
        'shipping_fulfillment_mode' => 'string',
        'shipping_assignment_status' => 'string',
        'shipping_operational_status' => 'string',
        'shipping_responsible_party' => 'string',
        'return_responsible_party' => 'string',
        'shipping_method_id' => 'integer',
        'shipping_cost' => 'float',
        'post_purchase_status' => 'string',
        'shipping_settlement_due_at' => 'datetime',
        'shipping_eta_from' => 'datetime',
        'shipping_eta_to' => 'datetime',
        'shipping_duration_snapshot' => 'array',
        'shipping_promise_key' => 'string',
        'sales_settlement_due_at' => 'datetime',
        'shipping_due_separated' => 'boolean',
        'shipping_due_override_reason' => 'string',
        'seller_shipping_allocation' => 'float',
        'platform_shipping_margin' => 'float',
        'shipping_workflow_status' => 'string',
        'shipping_customer_cost' => 'float',
        'shipping_seller_cost' => 'float',
        'shipping_seller_entitlement' => 'float',
        'return_shipping_cost' => 'float',
        'shipping_decided_at' => 'datetime',
        'shipping_decided_by' => 'integer',
        'seller_shipping_response_status' => 'string',
        'seller_shipping_response_due_at' => 'datetime',
        'seller_shipping_response_at' => 'datetime',
        'seller_shipping_support_ticket_id' => 'integer',
        'shipping_quantity' => 'integer',
        'shipping_product_subtotal' => 'float',
        'shipping_quantity_surcharge' => 'float',
        'is_shipping_free' => 'boolean',
        'order_group_id' => 'string',
        'verification_code' => 'string',
        'verification_status' => 'boolean',
        'seller_id' => 'integer',
        'seller_is' => 'string',
        'shipping_address_data' => 'object',
        'delivery_man_id' => 'integer',
        'deliveryman_assigned_at' => 'datetime',
        'deliveryman_charge' => 'float',
        'order_note' => 'string',
        'billing_address' => 'integer',
        'billing_address_data' => 'object',
        'order_type' => 'string',
        'extra_discount' => 'float',
        'extra_discount_type' => 'string',
        'free_delivery_bearer' => 'string',
        'checked' => 'boolean',
        'shipping_type' => 'string',
        'delivery_type' => 'string',
        'delivery_service_name' => 'string',
        'third_party_delivery_tracking_id' => 'string',
        'pickup_address_snapshot' => 'array',
        'customer_address_snapshot' => 'array',
        'shipping_price_snapshot' => 'array',
        'return_tracking_number' => 'string',
        'return_reason' => 'string',
        'return_started_at' => 'datetime',
        'return_completed_at' => 'datetime',
        'customer_delivery_confirmation_status' => 'string',
        'customer_delivery_confirmation_due_at' => 'datetime',
        'customer_delivery_confirmed_at' => 'datetime',
        'customer_delivery_confirmation_expired_at' => 'datetime',
        'edited_status' => 'integer'
    ];


    public function details(): HasMany
    {
        return $this->hasMany(OrderDetail::class)->orderBy('seller_id', 'ASC');
    }

    public function shippingProofs(): HasMany
    {
        return $this->hasMany(OrderShippingProof::class);
    }

    public function refundLedgers(): HasMany
    {
        return $this->hasMany(OrderRefundLedger::class);
    }

    public function shippingDecisionHistory(): HasMany
    {
        return $this->hasMany(OrderShippingDecision::class)->latest('id');
    }

    public function logisticsEvents(): HasMany
    {
        return $this->hasMany(OrderLogisticsEvent::class)->latest('id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function sellerName(): HasOne
    {
        return $this->hasOne(OrderDetail::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function postPurchaseInvoice(): HasOne
    {
        return $this->hasOne(PostPurchaseInvoice::class);
    }

    public function shipping(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(ShippingAddress::class, 'shipping_address');
    }

    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(ShippingAddress::class, 'billing_address');
    }

    public function deliveryMan(): BelongsTo
    {
        return $this->belongsTo(DeliveryMan::class, 'delivery_man_id');
    }

    /* delivery_man_review -> deliveryManReview */
    public function deliveryManReview(): HasOne
    {
        return $this->hasOne(Review::class, 'order_id')->whereNotNull('delivery_man_id');
    }

    /* order_transaction -> orderTransaction */
    public function orderTransaction(): HasOne
    {
        return $this->hasOne(OrderTransaction::class, 'order_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_code', 'code');
    }

    /* order_status_history -> orderStatusHistory */
    public function orderStatusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function activationInvoices(): HasMany
    {
        return $this->hasMany(CustomerActivationInvoice::class, 'order_id');
    }

    public function activationInvoice(): HasOne
    {
        return $this->hasOne(CustomerActivationInvoice::class, 'order_id')->latestOfMany();
    }

    public function insurance(): HasOne
    {
        return $this->hasOne(OrderInsurance::class);
    }

    public function sellerOrderInsurance(): HasOne
    {
        return $this->hasOne(SellerOrderInsurance::class);
    }

    public function adminGateDecisions(): HasMany
    {
        return $this->hasMany(OrderAdminGateDecision::class)->latest('id');
    }

    public function riskInvestigations(): HasMany
    {
        return $this->hasMany(OrderRiskInvestigation::class)->latest('id');
    }

    public function shippingDecisions(): HasMany
    {
        return $this->hasMany(OrderShippingDecision::class)->latest('id');
    }

    /* order_details -> orderDetails */
    public function orderDetails(): HasMany
    {
        return $this->hasMany(OrderDetail::class, 'order_id');
    }

    public function refundRequest(): HasOne
    {
        return $this->hasOne(RefundRequest::class, 'order_id');
    }

    /* offline_payments -> offlinePayments */
    public function offlinePayments(): BelongsTo
    {
        return $this->belongsTo(OfflinePayments::class, 'id', 'order_id');
    }

    /* verification_images -> verificationImages */
    public function verificationImages(): HasMany
    {
        return $this->hasMany(OrderDeliveryVerification::class, 'order_id');
    }

    public function orderEditHistory()
    {
        return $this->hasMany(OrderEditHistory::class, 'order_id');
    }

    public function purchaseLimitTransactions(): HasMany
    {
        return $this->hasMany(CustomerPurchaseLimitTransaction::class, 'order_id');
    }

    public function latestEditHistory(): HasOne
    {
        return $this->hasOne(OrderEditHistory::class, 'order_id')
            ->where(function ($query) {
                $query->where('order_due_amount', '>', 0)
                    ->orWhere('order_return_amount', '>', 0);
            })
            ->when(($this->edit_due_amount <= 0) || ($this->edit_return_amount <= 0),
                function ($query) {
                    $query->where(function ($q) {
                        $q->whereIn('order_due_payment_status', ['paid', 'unpaid'])
                            ->orWhereIn('order_return_payment_status', ['pending', 'returned']);
                    });
                }
            )
            ->latest('id');
    }


    public function getBillingAddressDataAttribute($value)
    {
        if (empty((array) $value)) {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value) : $value;
        return empty((array) $decoded) ? null : $decoded;
    }

    public function getShippingAddressDataAttribute($value)
    {
        if (empty((array) $value)) {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value) : $value;
        return empty((array) $decoded) ? null : $decoded;
    }


    protected static function boot(): void
    {
        parent::boot();
        //static::addGlobalScope(new RememberScope);
    }
}
