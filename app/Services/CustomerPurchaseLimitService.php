<?php

namespace App\Services;

use App\Models\CustomerPurchasePackageSubscription;
use App\Models\CustomerPurchaseLimitTransaction;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Utils\CartManager;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CustomerPurchaseLimitService
{
    /** Customer packages are suspended; historical records remain unchanged. */
    public function isFeatureEnabled(): bool
    {
        return false;
    }

    private function getCustomerId(mixed $customer): ?int
    {
        if (is_object($customer)) {
            return $customer->id ? (int) $customer->id : null;
        }

        return $customer ? (int) $customer : null;
    }

    public function getActiveSubscription(mixed $customer): ?CustomerPurchasePackageSubscription
    {
        $customerId = $this->getCustomerId($customer);

        if (!$customerId) {
            return null;
        }

        return CustomerPurchasePackageSubscription::query()
            ->with('package')
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', Carbon::now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', Carbon::now());
            })
            ->latest('activated_at')
            ->latest('id')
            ->first();
    }

    private function getActiveSubscriptionQuery(int $customerId)
    {
        return CustomerPurchasePackageSubscription::query()
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', Carbon::now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', Carbon::now());
            })
            ->latest('activated_at')
            ->latest('id');
    }

    public function getLimitSummary(mixed $customer): array
    {
        if (!$this->isFeatureEnabled()) {
            return [
                'has_active_package' => true,
                'feature_enabled' => false,
                'subscription' => null,
                'package_limit' => 0.0,
                'used_limit' => 0.0,
                'extra_credit_limit' => 0.0,
                'admin_adjustment_limit' => 0.0,
                'total_limit' => 0.0,
                'available_limit' => 0.0,
            ];
        }

        $subscription = $this->getActiveSubscription($customer);

        if (!$subscription) {
            return [
                'has_active_package' => false,
                'subscription' => null,
                'package_limit' => 0.0,
                'used_limit' => 0.0,
                'extra_credit_limit' => 0.0,
                'admin_adjustment_limit' => 0.0,
                'total_limit' => 0.0,
                'available_limit' => 0.0,
            ];
        }

        $packageLimit = (float) $subscription->package_purchase_limit;
        $usedLimit = (float) $subscription->used_purchase_limit;
        $extraCreditLimit = (float) $subscription->extra_credit_limit;
        $adminAdjustmentLimit = (float) $subscription->admin_adjustment_limit;
        $totalLimit = $packageLimit + $extraCreditLimit + $adminAdjustmentLimit;
        $availableLimit = max($totalLimit - $usedLimit, 0);

        return [
            'has_active_package' => true,
            'subscription' => $subscription,
            'package_limit' => $packageLimit,
            'used_limit' => $usedLimit,
            'extra_credit_limit' => $extraCreditLimit,
            'admin_adjustment_limit' => $adminAdjustmentLimit,
            'total_limit' => $totalLimit,
            'available_limit' => $availableLimit,
        ];
    }

    public function getCheckedCartProductTotal(): float
    {
        return $this->getCartProductTotal(CartManager::getCartListQuery(type: 'checked'));
    }

    public function getCartProductTotal(Collection|EloquentCollection|array|null $cartList): float
    {
        if (empty($cartList)) {
            return 0.0;
        }

        return (float) collect($cartList)->sum(function ($cartItem) {
            $price = (float) data_get($cartItem, 'price', 0);
            $discount = (float) data_get($cartItem, 'discount', 0);
            $quantity = (int) data_get($cartItem, 'quantity', 0);

            return max($price - $discount, 0) * $quantity;
        });
    }

    public function getCheckoutLimitAssessment(mixed $customer, Collection|EloquentCollection|array|null $cartList = null): array
    {
        $summary = $this->getLimitSummary($customer);
        $cartProductTotal = is_null($cartList)
            ? $this->getCheckedCartProductTotal()
            : $this->getCartProductTotal($cartList);
        $shortage = $this->isFeatureEnabled() ? max($cartProductTotal - $summary['available_limit'], 0) : 0.0;

        return [
            'customer_id' => $this->getCustomerId($customer),
            'has_active_package' => $summary['has_active_package'],
            'subscription' => $summary['subscription'],
            'cart_product_total' => $cartProductTotal,
            'package_limit' => $summary['package_limit'],
            'used_limit' => $summary['used_limit'],
            'extra_credit_limit' => $summary['extra_credit_limit'],
            'admin_adjustment_limit' => $summary['admin_adjustment_limit'],
            'total_limit' => $summary['total_limit'],
            'available_limit' => $summary['available_limit'],
            'shortage' => $shortage,
            'can_checkout' => !$this->isFeatureEnabled() || ($summary['has_active_package'] && $shortage <= 0),
            'extra_credit' => $this->isFeatureEnabled() ? $this->calculateExtraCreditSuggestion($shortage) : $this->calculateExtraCreditSuggestion(0),
        ];
    }

    public function validateCheckoutAccess(mixed $customer, Collection|EloquentCollection|array|null $cartList = null): array
    {
        $assessment = $this->getCheckoutLimitAssessment(customer: $customer, cartList: $cartList);

        if (!$this->isFeatureEnabled()) {
            return [
                'status' => 1,
                'code' => 'customer_purchase_packages_suspended',
                'message' => translate('purchase_limit_available'),
                'assessment' => $assessment,
            ];
        }

        if (!$assessment['has_active_package']) {
            return [
                'status' => 0,
                'code' => 'customer_purchase_package_required',
                'message' => translate('you_need_an_active_customer_purchase_package_before_checkout'),
                'assessment' => $assessment,
            ];
        }

        if ($assessment['shortage'] > 0) {
            $shortage = setCurrencySymbol(amount: usdToDefaultCurrency(amount: $assessment['shortage']));
            $availableLimit = setCurrencySymbol(amount: usdToDefaultCurrency(amount: $assessment['available_limit']));
            $cartProductTotal = setCurrencySymbol(amount: usdToDefaultCurrency(amount: $assessment['cart_product_total']));
            $message = translate('your_available_purchase_limit_is_not_enough_for_this_cart') . '. '
                . translate('Cart_product_total') . ': ' . $cartProductTotal . ', '
                . translate('Available_limit') . ': ' . $availableLimit . ', '
                . translate('Shortage') . ': ' . $shortage . '.';

            if ($assessment['extra_credit']['available']) {
                $creditAmount = setCurrencySymbol(amount: usdToDefaultCurrency(amount: $assessment['extra_credit']['credit_amount']));
                $paymentAmount = setCurrencySymbol(amount: usdToDefaultCurrency(amount: $assessment['extra_credit']['payment_amount']));
                $message .= ' ' . translate('You_can_buy_extra_credit') . ': ' . $creditAmount
                    . ' (' . translate('payment_amount') . ': ' . $paymentAmount . ').';
            }

            return [
                'status' => 0,
                'code' => 'customer_purchase_limit_insufficient',
                'message' => $message,
                'assessment' => $assessment,
            ];
        }

        return [
            'status' => 1,
            'code' => 'customer_purchase_limit_available',
            'message' => translate('purchase_limit_available'),
            'assessment' => $assessment,
        ];
    }

    public function getExtraCreditSettings(): array
    {
        return [
            'enabled' => (bool) getWebConfig(name: 'customer_extra_credit_status'),
            'minimum_amount' => (float) (getWebConfig(name: 'customer_extra_credit_min_amount') ?? 50),
            'step_amount' => (float) (getWebConfig(name: 'customer_extra_credit_step_amount') ?? 100),
            'rate' => (float) (getWebConfig(name: 'customer_extra_credit_rate') ?? 10),
            'maximum_amount' => (float) (getWebConfig(name: 'customer_extra_credit_max_amount') ?? 0),
            'rounding_rule' => getWebConfig(name: 'customer_extra_credit_rounding_rule') ?: 'ceil_step',
        ];
    }

    public function calculateExtraCreditSuggestion(float|int $shortage): array
    {
        $settings = $this->getExtraCreditSettings();
        $shortage = max((float) $shortage, 0);

        if (!$settings['enabled'] || $shortage <= 0) {
            return [
                'enabled' => $settings['enabled'],
                'available' => false,
                'shortage' => $shortage,
                'credit_amount' => 0.0,
                'payment_amount' => 0.0,
                'settings' => $settings,
                'reason' => $shortage <= 0 ? 'no_shortage' : 'extra_credit_disabled',
            ];
        }

        $minimumAmount = max($settings['minimum_amount'], 0.01);
        $stepAmount = max($settings['step_amount'], 0.01);
        $creditAmount = max($shortage, $minimumAmount);

        if ($settings['rounding_rule'] === 'ceil_step') {
            $creditAmount = ceil($creditAmount / $stepAmount) * $stepAmount;
        }

        $maximumAmount = (float) $settings['maximum_amount'];
        if ($maximumAmount > 0 && $creditAmount > $maximumAmount) {
            return [
                'enabled' => true,
                'available' => false,
                'shortage' => $shortage,
                'credit_amount' => $creditAmount,
                'payment_amount' => 0.0,
                'settings' => $settings,
                'reason' => 'extra_credit_maximum_amount_exceeded',
            ];
        }

        return [
            'enabled' => true,
            'available' => true,
            'shortage' => $shortage,
            'credit_amount' => $creditAmount,
            'payment_amount' => $creditAmount * ((float) $settings['rate'] / 100),
            'settings' => $settings,
            'reason' => null,
        ];
    }

    public function calculateExtraCreditPurchase(float|int $requestedCreditAmount): array
    {
        $settings = $this->getExtraCreditSettings();
        $requestedCreditAmount = max((float) $requestedCreditAmount, 0);

        if (!$settings['enabled']) {
            return [
                'status' => 0,
                'credit_amount' => 0.0,
                'payment_amount' => 0.0,
                'settings' => $settings,
                'reason' => 'extra_credit_disabled',
            ];
        }

        $minimumAmount = max((float) $settings['minimum_amount'], 0.01);
        $stepAmount = max((float) $settings['step_amount'], 0.01);
        $creditAmount = max($requestedCreditAmount, $minimumAmount);

        if ($settings['rounding_rule'] === 'ceil_step') {
            $creditAmount = ceil($creditAmount / $stepAmount) * $stepAmount;
        }

        $maximumAmount = (float) $settings['maximum_amount'];
        if ($maximumAmount > 0 && $creditAmount > $maximumAmount) {
            return [
                'status' => 0,
                'credit_amount' => $creditAmount,
                'payment_amount' => 0.0,
                'settings' => $settings,
                'reason' => 'extra_credit_maximum_amount_exceeded',
            ];
        }

        $paymentAmount = $creditAmount * ((float) $settings['rate'] / 100);
        if ($paymentAmount <= 0) {
            return [
                'status' => 0,
                'credit_amount' => $creditAmount,
                'payment_amount' => 0.0,
                'settings' => $settings,
                'reason' => 'invalid_extra_credit_rate',
            ];
        }

        return [
            'status' => 1,
            'credit_amount' => round($creditAmount, 2),
            'payment_amount' => round($paymentAmount, 2),
            'settings' => $settings,
            'reason' => null,
        ];
    }

    public function getOrderProductLimitAmount(Order $order): float
    {
        $order->loadMissing('details');

        return (float) $order->details->sum(function ($detail) {
            return max(((float) $detail->price * (int) $detail->qty) - (float) $detail->discount, 0);
        });
    }

    public function getOrderDetailProductLimitAmount(OrderDetail $orderDetail): float
    {
        return max(((float) $orderDetail->price * (int) $orderDetail->qty) - (float) $orderDetail->discount, 0);
    }

    public function debitOrderLimit(Order $order): array
    {
        if (!$this->isFeatureEnabled()) {
            return ['status' => 1, 'message' => 'customer_purchase_packages_suspended'];
        }

        if ((int) $order->is_guest === 1 || !$order->customer_id) {
            return ['status' => 0, 'message' => 'guest_order'];
        }

        if ($order->activation_status === 'activation_pending') {
            return ['status' => 0, 'message' => 'activation_pending'];
        }

        return DB::transaction(function () use ($order) {
            $existingTransaction = CustomerPurchaseLimitTransaction::where('order_id', $order->id)
                ->where('transaction_type', 'order_purchase')
                ->first();

            if ($existingTransaction) {
                return ['status' => 1, 'transaction' => $existingTransaction, 'message' => 'already_debited'];
            }

            $subscription = $this->getActiveSubscriptionQuery((int) $order->customer_id)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                throw new RuntimeException('Customer purchase package is required before order placement.');
            }

            $productAmount = $this->getOrderProductLimitAmount($order);
            if ($productAmount <= 0) {
                return ['status' => 1, 'transaction' => null, 'message' => 'empty_product_amount'];
            }

            $totalLimit = (float) $subscription->package_purchase_limit
                + (float) $subscription->extra_credit_limit
                + (float) $subscription->admin_adjustment_limit;
            $availableLimit = max($totalLimit - (float) $subscription->used_purchase_limit, 0);

            if ($productAmount > $availableLimit) {
                throw new RuntimeException('Customer purchase limit is not enough for this order.');
            }

            $usedLimit = (float) $subscription->used_purchase_limit + $productAmount;
            $balanceAfter = max($totalLimit - $usedLimit, 0);
            $subscription->update([
                'used_purchase_limit' => $usedLimit,
                'available_purchase_limit' => $balanceAfter,
            ]);

            $transaction = CustomerPurchaseLimitTransaction::create([
                'customer_id' => $order->customer_id,
                'customer_purchase_package_subscription_id' => $subscription->id,
                'customer_purchase_package_id' => $subscription->customer_purchase_package_id,
                'order_id' => $order->id,
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => 'order_purchase',
                'credit' => 0,
                'debit' => $productAmount,
                'balance_after' => $balanceAfter,
                'product_amount' => $productAmount,
                'paid_amount' => 0,
                'reference' => (string) $order->id,
                'note' => 'Product amount deducted from customer purchase limit after order placement.',
                'metadata' => [
                    'order_group_id' => $order->order_group_id,
                    'order_status' => $order->order_status,
                    'payment_method' => $order->payment_method,
                ],
            ]);

            return ['status' => 1, 'transaction' => $transaction, 'message' => 'debited'];
        });
    }

    public function returnOrderLimit(Order $order, string $transactionType = 'order_cancel_return', ?string $reference = null, ?float $amount = null, array $metadata = []): array
    {
        if ((int) $order->is_guest === 1 || !$order->customer_id) {
            return ['status' => 0, 'message' => 'guest_order'];
        }

        $reference = $reference ?: (string) $order->id;

        return DB::transaction(function () use ($order, $transactionType, $reference, $amount, $metadata) {
            $existingReturn = CustomerPurchaseLimitTransaction::where('order_id', $order->id)
                ->where('transaction_type', $transactionType)
                ->where('reference', $reference)
                ->first();

            if ($existingReturn) {
                return ['status' => 1, 'transaction' => $existingReturn, 'message' => 'already_returned'];
            }

            $debitTransaction = CustomerPurchaseLimitTransaction::where('order_id', $order->id)
                ->where('transaction_type', 'order_purchase')
                ->first();

            if (!$debitTransaction) {
                return ['status' => 0, 'message' => 'order_limit_debit_not_found'];
            }

            $subscription = CustomerPurchasePackageSubscription::where('id', $debitTransaction->customer_purchase_package_subscription_id)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                return ['status' => 0, 'message' => 'subscription_not_found'];
            }

            $alreadyReturned = (float) CustomerPurchaseLimitTransaction::where('order_id', $order->id)
                ->where('transaction_type', '!=', 'order_purchase')
                ->sum('credit');
            $remainingDebitAmount = max((float) $debitTransaction->debit - $alreadyReturned, 0);
            $returnAmount = $amount ?? $remainingDebitAmount;
            $returnAmount = min(max($returnAmount, 0), $remainingDebitAmount, (float) $subscription->used_purchase_limit);

            if ($returnAmount <= 0) {
                return ['status' => 1, 'transaction' => null, 'message' => 'empty_return_amount'];
            }

            $usedLimit = max((float) $subscription->used_purchase_limit - $returnAmount, 0);
            $totalLimit = (float) $subscription->package_purchase_limit
                + (float) $subscription->extra_credit_limit
                + (float) $subscription->admin_adjustment_limit;
            $balanceAfter = max($totalLimit - $usedLimit, 0);

            $subscription->update([
                'used_purchase_limit' => $usedLimit,
                'available_purchase_limit' => $balanceAfter,
            ]);

            $transaction = CustomerPurchaseLimitTransaction::create([
                'customer_id' => $order->customer_id,
                'customer_purchase_package_subscription_id' => $subscription->id,
                'customer_purchase_package_id' => $subscription->customer_purchase_package_id,
                'order_id' => $order->id,
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => $transactionType,
                'credit' => $returnAmount,
                'debit' => 0,
                'balance_after' => $balanceAfter,
                'product_amount' => $returnAmount,
                'paid_amount' => 0,
                'reference' => $reference,
                'note' => 'Product amount returned to customer purchase limit.',
                'metadata' => $metadata,
            ]);

            return ['status' => 1, 'transaction' => $transaction, 'message' => 'returned'];
        });
    }

    public function returnRefundedOrderDetailLimit(Order $order, OrderDetail $orderDetail, string|int $refundId): array
    {
        return $this->returnOrderLimit(
            order: $order,
            transactionType: 'order_refund_return',
            reference: 'refund:' . $refundId,
            amount: $this->getOrderDetailProductLimitAmount($orderDetail),
            metadata: [
                'refund_id' => $refundId,
                'order_detail_id' => $orderDetail->id,
                'product_id' => $orderDetail->product_id,
            ]
        );
    }

    public function adjustCustomerLimit(mixed $customer, float|int $amount, string $type, ?string $note = null, ?int $adminId = null): array
    {
        $customerId = $this->getCustomerId($customer);
        $amount = round(max((float) $amount, 0), 2);
        $type = in_array($type, ['add', 'subtract'], true) ? $type : '';

        if (!$customerId || $amount <= 0 || !$type) {
            return ['status' => 0, 'message' => 'invalid_adjustment_data'];
        }

        return DB::transaction(function () use ($customerId, $amount, $type, $note, $adminId) {
            $subscription = $this->getActiveSubscriptionQuery($customerId)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                return ['status' => 0, 'message' => 'active_customer_purchase_package_not_found'];
            }

            $oldAdminAdjustment = (float) $subscription->admin_adjustment_limit;
            $oldTotalLimit = (float) $subscription->package_purchase_limit
                + (float) $subscription->extra_credit_limit
                + $oldAdminAdjustment;
            $usedLimit = (float) $subscription->used_purchase_limit;
            $newAdminAdjustment = $type === 'add'
                ? $oldAdminAdjustment + $amount
                : $oldAdminAdjustment - $amount;
            $newTotalLimit = (float) $subscription->package_purchase_limit
                + (float) $subscription->extra_credit_limit
                + $newAdminAdjustment;

            if ($type === 'subtract' && $newTotalLimit < $usedLimit) {
                return [
                    'status' => 0,
                    'message' => 'adjustment_would_make_limit_less_than_used_amount',
                    'used_limit' => $usedLimit,
                    'available_limit' => max($oldTotalLimit - $usedLimit, 0),
                ];
            }

            $balanceAfter = max($newTotalLimit - $usedLimit, 0);
            $subscription->update([
                'admin_adjustment_limit' => $newAdminAdjustment,
                'available_purchase_limit' => $balanceAfter,
            ]);

            $transaction = CustomerPurchaseLimitTransaction::create([
                'customer_id' => $customerId,
                'customer_purchase_package_subscription_id' => $subscription->id,
                'customer_purchase_package_id' => $subscription->customer_purchase_package_id,
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => $type === 'add' ? 'admin_limit_add' : 'admin_limit_subtract',
                'credit' => $type === 'add' ? $amount : 0,
                'debit' => $type === 'subtract' ? $amount : 0,
                'balance_after' => $balanceAfter,
                'product_amount' => 0,
                'paid_amount' => 0,
                'reference' => 'admin-adjustment:' . Str::uuid(),
                'note' => $note,
                'created_by_admin_id' => $adminId,
                'metadata' => [
                    'old_admin_adjustment_limit' => $oldAdminAdjustment,
                    'new_admin_adjustment_limit' => $newAdminAdjustment,
                    'old_total_limit' => $oldTotalLimit,
                    'new_total_limit' => $newTotalLimit,
                    'used_limit' => $usedLimit,
                ],
            ]);

            return ['status' => 1, 'transaction' => $transaction, 'message' => 'adjusted'];
        });
    }
}
