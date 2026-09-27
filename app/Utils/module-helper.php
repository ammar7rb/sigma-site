<?php

use App\Events\AddFundToWalletEvent;
use App\Models\AdminWallet;
use App\Models\CustomerPurchaseLimitTransaction;
use App\Models\CustomerPurchasePackageSubscription;
use App\Models\CustomerActivationInvoice;
use App\Models\Order;
use App\Models\OrderEditHistory;
use App\Models\ShippingAddress;
use App\Models\SellerInsurance;
use App\Models\SellerOrderInsurance;
use App\Models\SellerPackageSubscription;
use App\Models\SellerBalanceDeposit;
use App\Models\PostPurchaseInvoice;
use App\Models\User;
use App\Services\CustomerActivationInvoiceService;
use App\Services\PostPurchaseInvoiceService;
use App\Services\SellerInsuranceService;
use App\Services\SellerOrderInsuranceService;
use App\Services\SellerPackagePurchaseService;
use App\Services\SellerBalanceDepositService;
use App\Services\CustomerPostPurchaseInsuranceService;
use App\Utils\Convert;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;

if (!function_exists('digital_payment_success')) {
    function digital_payment_success($paymentData): void
    {
        if (isset($paymentData) && $paymentData['is_paid'] == 1) {
            $additionalData = json_decode($paymentData['additional_data'], true);

            $addCustomer = null;
            $newCustomerInfo = $additionalData['new_customer_info'] ?? null;

            if ($newCustomerInfo) {
                $checkCustomer = User::where(['email' => $newCustomerInfo['email']])->orWhere(['phone' => $newCustomerInfo['phone']])->first();
                if (!$checkCustomer) {
                    $addCustomer = User::create([
                        'name' => $newCustomerInfo['name'],
                        'f_name' => $newCustomerInfo['name'],
                        'l_name' => $newCustomerInfo['l_name'],
                        'email' => $newCustomerInfo['email'],
                        'phone' => $newCustomerInfo['phone'],
                        'is_active' => 1,
                        'password' => bcrypt($newCustomerInfo['password']),
                        'referral_code' => $newCustomerInfo['referral_code'],
                    ]);
                } else {
                    $addCustomer = $checkCustomer;
                }
                session()->put('newRegisterCustomerInfo', $addCustomer);

                if ($additionalData['is_guest']) {
                    $addressId = $additionalData['address_id'] ?? null;
                    $billingAddressId = $additionalData['billing_address_id'] ?? null;
                    ShippingAddress::where(['customer_id' => $additionalData['customer_id'], 'is_guest' => 1, 'id' => $addressId])
                        ->update(['customer_id' => $addCustomer['id'], 'is_guest' => 0]);
                    ShippingAddress::where(['customer_id' => $additionalData['customer_id'], 'is_guest' => 1, 'id' => $billingAddressId])
                        ->update(['customer_id' => $addCustomer['id'], 'is_guest' => 0]);
                }
            }

            session()->put('payment_mode', $additionalData['payment_mode'] ?? 'web');

            if (isset($additionalData['is_guest']) && $additionalData['is_guest'] == 0) {
                $user = User::where(['id' => $additionalData['customer_id']])->first();
                request()->merge(['user' => $user]);
            }

            $requestObj = [
                'customer_id' => $additionalData['customer_id'],
                'is_guest' => $additionalData['is_guest'] ?? 0,
                'guest_id' => ($additionalData['is_guest_in_order'] ?? 0) ? $additionalData['customer_id'] : null,
                'payment_request_from' => $additionalData['payment_mode'] ?? 'web',
            ];
            request()->merge($requestObj);

            $activationInvoiceService = app(CustomerActivationInvoiceService::class);
            $postPurchaseInvoiceService = app(PostPurchaseInvoiceService::class);
            $customerForActivation = $addCustomer ?: User::find($additionalData['customer_id']);
            $holdForActivation = ! $postPurchaseInvoiceService->isEnabled()
                && $customerForActivation
                && (int) ($additionalData['is_guest_in_order'] ?? 0) === 0
                && $activationInvoiceService->shouldHoldPaidOrderForActivation($customerForActivation);

            $orderIds = OrderManager::generateOrder(data: [
                'is_guest' => $additionalData['is_guest_in_order'] ?? 0,
                'guest_id' => ($additionalData['is_guest_in_order'] ?? 0) ? $additionalData['customer_id'] : null,
                'customer_id' => $additionalData['customer_id'],
                'order_status' => $holdForActivation ? 'pending' : 'confirmed',
                'activation_status' => $holdForActivation ? 'activation_pending' : 'not_required',
                'activation_pending_at' => $holdForActivation ? now() : null,
                'payment_method' => $paymentData['payment_method'],
                'payment_status' => 'paid',
                'transaction_ref' => $paymentData['transaction_id'],
                'new_customer_id' => $addCustomer ? $addCustomer['id'] : ($additionalData['new_customer_id'] ?? null),
                'newCustomerRegister' => $addCustomer,

                'order_note' => $additionalData['order_note'],
                'coupon_code' => $additionalData['coupon_code'] ?? null,
                'address_id' => $additionalData['address_id'] ?? null,
                'billing_address_id' => $additionalData['billing_address_id'] ?? null,
                'requestObj' => $requestObj,
            ]);

            $activationInvoice = $holdForActivation
                ? $activationInvoiceService->createForPaidOrderGroup($orderIds)
                : null;

            $postPurchaseInvoice = $postPurchaseInvoiceService->isEnabled()
                ? PostPurchaseInvoice::query()->whereIn('order_id', $orderIds)->latest('id')->first()
                : null;

            if ($activationInvoice) {
                session([
                    'customer_activation_invoice_id' => $activationInvoice->id,
                    'customer_activation_hold_message' => $activationInvoiceService->getActivationHoldMessage($activationInvoice),
                ]);
            }

            if ($postPurchaseInvoice?->isPayable()) {
                session([
                    'post_purchase_invoice_id' => $postPurchaseInvoice->id,
                    'post_purchase_invoice_message' => 'Your merchandise payment was received. Complete tax and order insurance to continue.',
                ]);
            }

            if (!$activationInvoice) {
                foreach ($orderIds as $orderId) {
                    OrderManager::generateReferBonusForFirstOrder(orderId: $orderId);
                }
            }
        }
    }
}

if (!function_exists('digital_payment_fail')) {
    function digital_payment_fail($payment_data)
    {

    }
}

if (!function_exists('customer_order_insurance_payment_success')) {
    function customer_order_insurance_payment_success($paymentData): void
    {
        if (!isset($paymentData) || (int) ($paymentData['is_paid'] ?? 0) !== 1) {
            return;
        }
        $additional = json_decode($paymentData['additional_data'] ?? '{}', true) ?: [];
        $insurance = \App\Models\OrderInsurance::query()->find($additional['order_insurance_id'] ?? null);
        if (!$insurance || (int) $insurance->customer_id !== (int) ($paymentData['payer_id'] ?? 0)) {
            return;
        }
        app(CustomerPostPurchaseInsuranceService::class)->markPaid(
            $insurance,
            'digital_payment',
            (string) ($paymentData['transaction_id'] ?? ('customer-order-insurance-' . $insurance->id)),
        );
    }
}

if (!function_exists('customer_order_insurance_payment_fail')) {
    function customer_order_insurance_payment_fail($paymentData): void
    {
        // A failed attempt leaves the claim payable until payment_due_at.
    }
}
if (!function_exists('customer_order_edit_pay_due_amount_success')) {
    /**
     * @throws Throwable
     */
    function customer_order_edit_pay_due_amount_success($payment_data): void
    {
        if (!isset($payment_data) || ($payment_data['is_paid'] ?? 0) != 1) {
            return;
        }
        $additionalData = json_decode($payment_data['additional_data'] ?? '{}', true);
        if (empty($additionalData['order_id'])) {
            return;
        }
        $order = Order::where('id', $additionalData['order_id'])->first();
        DB::transaction(function () use ($additionalData, $payment_data, $order) {
            $order->update([
                'edit_due_amount' => 0,
                'order_amount' => $additionalData['order_amount'] ?? 0,
                'payment_status' => 'paid',
            ]);
            OrderEditHistory::where('order_id', $additionalData['order_id'])
                ->latest('id')
                ->limit(1)
                ->update([
                    'order_due_payment_status' => 'paid',
                    'order_due_payment_method' => $payment_data['payment_method'] ?? null,
                    'order_due_transaction_ref' => $payment_data['transaction_id'] ?? '',
                    'order_due_payment_note' => $payment_data['order_due_payment_note'] ?? '',
                ]);
        });
        OrderManager::sendPushNotificationAfterDuePayment(order: $order);
        AdminWallet::where(['admin_id' => 1])->increment('pending_amount', $additionalData['order_amount']);
    }
}
if (!function_exists('customer_order_edit_pay_due_amount_failed')) {
    function customer_order_edit_pay_due_amount_failed($payment_data): void
    {
        if (!isset($payment_data)) {
            return;
        }
        $additionalData = json_decode($payment_data['additional_data'] ?? '', true);
        if (empty($additionalData['order_id'])) {
            return;
        }
        OrderEditHistory::where('order_id', $additionalData['order_id'])
            ->latest('id')
            ->limit(1)
            ->update([
                'order_due_payment_status' => 'unpaid',
                'order_due_transaction_ref' => $payment_data['transaction_id'] ?? '',
            ]);
    }
}

// Add Fund To Wallet - Success
if (!function_exists('add_fund_to_wallet_success')) {
    function add_fund_to_wallet_success($payment_data): void
    {
        if (isset($payment_data) && $payment_data['is_paid'] == 1) {
            $additional_data = json_decode($payment_data['additional_data'], true);
            session()->put('payment_mode', ($additional_data['payment_mode'] ?? 'web'));

            $paymentAmount = Convert::usdPaymentModule(floatval($payment_data['payment_amount']), $payment_data['currency_code']);
            $paymentAmount = usdToDefaultCurrency(amount: $paymentAmount);
            $wallet_transaction = \Illuminate\Support\Facades\DB::transaction(function () use ($payment_data, $paymentAmount) {
                $payment = \App\Models\PaymentRequest::query()->lockForUpdate()->findOrFail($payment_data['id']);
                if ($payment->is_paid != 1 || $payment->attribute !== 'add_funds_to_wallet') return null;
                $metadata = json_decode($payment->additional_data ?? '{}', true) ?: [];
                if (!empty($metadata['wallet_credit_transaction_id'])) {
                    return null;
                }
                \App\Models\User::query()->lockForUpdate()->findOrFail($payment_data['payer_id']);
                $transaction = CustomerManager::create_wallet_transaction($payment_data['payer_id'], $paymentAmount, 'add_fund', 'add_funds_to_wallet', $payment_data);
                if (!$transaction) {
                    throw new \RuntimeException('purchase_wallet_deposit_credit_failed');
                }
                $metadata['wallet_credit_transaction_id'] = $transaction->transaction_id;
                $payment->update(['additional_data' => json_encode($metadata)]);
                return $transaction;
            });

            if ($wallet_transaction) {
                try {
                    $data = [
                        'walletTransaction' => $wallet_transaction,
                        'userName' => $wallet_transaction->user['f_name'],
                        'userType' => 'customer',
                        'templateName' => 'add-fund-to-wallet',
                        'subject' => translate('add_fund_to_wallet'),
                        'title' => translate('add_fund_to_wallet'),
                    ];
                    event(new AddFundToWalletEvent(email: $wallet_transaction->user['email'], data: $data));
                } catch (Exception $ex) {
                    info($ex);
                }
            }
        }
    }
}

// Add Fund To Wallet - Fail
if (!function_exists('customer_insurance_deposit_success')) {
    function customer_insurance_deposit_success($payment_data): void
    {
        app(\App\Services\CustomerInsuranceDepositService::class)->complete($payment_data);
    }
}

if (!function_exists('add_fund_to_wallet_fail')) {
    function add_fund_to_wallet_fail($payment_data)
    {

    }
}

if (!function_exists('customer_purchase_package_payment_success')) {
    function customer_purchase_package_payment_success($payment_data): void
    {
        if (!isset($payment_data) || ($payment_data['is_paid'] ?? 0) != 1) {
            return;
        }

        $additionalData = json_decode($payment_data['additional_data'] ?? '{}', true);
        $customerId = (int) ($additionalData['customer_id'] ?? $payment_data['payer_id'] ?? 0);
        $packageId = (int) ($additionalData['package_id'] ?? $payment_data['attribute_id'] ?? 0);
        $packageName = $additionalData['package_name'] ?? null;
        $packagePrice = (float) ($additionalData['package_price'] ?? 0);
        $purchaseLimit = (float) ($additionalData['purchase_limit'] ?? 0);

        if (!$customerId || !$packageId || $packagePrice <= 0 || $purchaseLimit <= 0) {
            return;
        }

        session()->put('payment_mode', $additionalData['payment_mode'] ?? 'web');

        DB::transaction(function () use ($payment_data, $additionalData, $customerId, $packageId, $packageName, $packagePrice, $purchaseLimit) {
            $existingTransaction = CustomerPurchaseLimitTransaction::where('payment_request_id', $payment_data['id'])->first();
            if ($existingTransaction) {
                return;
            }

            CustomerPurchasePackageSubscription::where('customer_id', $customerId)
                ->where('status', 'active')
                ->update([
                    'status' => 'replaced',
                    'cancelled_at' => now(),
                ]);

            $subscription = CustomerPurchasePackageSubscription::create([
                'customer_id' => $customerId,
                'customer_purchase_package_id' => $packageId,
                'package_name' => $packageName,
                'paid_package_price' => $packagePrice,
                'package_purchase_limit' => $purchaseLimit,
                'used_purchase_limit' => 0,
                'extra_credit_limit' => 0,
                'admin_adjustment_limit' => 0,
                'available_purchase_limit' => $purchaseLimit,
                'status' => 'active',
                'payment_status' => 'paid',
                'payment_method' => $payment_data['payment_method'] ?? null,
                'payment_reference' => $payment_data['transaction_id'] ?? null,
                'starts_at' => now(),
                'activated_at' => now(),
                'metadata' => [
                    'payment_request_id' => $payment_data['id'],
                    'payment_amount' => $payment_data['payment_amount'] ?? null,
                    'currency_code' => $payment_data['currency_code'] ?? null,
                    'is_custom' => $additionalData['is_custom'] ?? false,
                ],
            ]);

            CustomerPurchaseLimitTransaction::create([
                'customer_id' => $customerId,
                'customer_purchase_package_subscription_id' => $subscription->id,
                'customer_purchase_package_id' => $packageId,
                'payment_request_id' => $payment_data['id'],
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => 'package_purchase',
                'credit' => $purchaseLimit,
                'debit' => 0,
                'balance_after' => $purchaseLimit,
                'product_amount' => 0,
                'paid_amount' => $packagePrice,
                'reference' => $payment_data['transaction_id'] ?? null,
                'note' => 'Customer purchase package activated after payment.',
                'metadata' => [
                    'package_name' => $packageName,
                    'payment_method' => $payment_data['payment_method'] ?? null,
                    'payment_amount' => $payment_data['payment_amount'] ?? null,
                    'currency_code' => $payment_data['currency_code'] ?? null,
                ],
            ]);
        });
    }
}

if (!function_exists('customer_purchase_package_payment_fail')) {
    function customer_purchase_package_payment_fail($payment_data): void
    {

    }
}

if (!function_exists('customer_extra_credit_payment_success')) {
    function customer_extra_credit_payment_success($payment_data): void
    {
        if (!isset($payment_data) || ($payment_data['is_paid'] ?? 0) != 1) {
            return;
        }

        $additionalData = json_decode($payment_data['additional_data'] ?? '{}', true);
        $customerId = (int) ($additionalData['customer_id'] ?? $payment_data['payer_id'] ?? 0);
        $subscriptionId = (int) ($additionalData['subscription_id'] ?? $payment_data['attribute_id'] ?? 0);
        $creditAmount = (float) ($additionalData['credit_amount'] ?? 0);
        $paidAmount = (float) ($additionalData['paid_amount'] ?? 0);

        if (!$customerId || !$subscriptionId || $creditAmount <= 0 || $paidAmount <= 0) {
            return;
        }

        session()->put('payment_mode', $additionalData['payment_mode'] ?? 'web');

        DB::transaction(function () use ($payment_data, $additionalData, $customerId, $subscriptionId, $creditAmount, $paidAmount) {
            $existingTransaction = CustomerPurchaseLimitTransaction::where('payment_request_id', $payment_data['id'])->first();
            if ($existingTransaction) {
                return;
            }

            $subscription = CustomerPurchasePackageSubscription::where('id', $subscriptionId)
                ->where('customer_id', $customerId)
                ->where('status', 'active')
                ->where('payment_status', 'paid')
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                return;
            }

            $extraCreditLimit = (float) $subscription->extra_credit_limit + $creditAmount;
            $totalLimit = (float) $subscription->package_purchase_limit
                + $extraCreditLimit
                + (float) $subscription->admin_adjustment_limit;
            $balanceAfter = max($totalLimit - (float) $subscription->used_purchase_limit, 0);

            $subscription->update([
                'extra_credit_limit' => $extraCreditLimit,
                'available_purchase_limit' => $balanceAfter,
            ]);

            CustomerPurchaseLimitTransaction::create([
                'customer_id' => $customerId,
                'customer_purchase_package_subscription_id' => $subscription->id,
                'customer_purchase_package_id' => $subscription->customer_purchase_package_id,
                'payment_request_id' => $payment_data['id'],
                'transaction_id' => (string) Str::uuid(),
                'transaction_type' => 'extra_credit_purchase',
                'credit' => $creditAmount,
                'debit' => 0,
                'balance_after' => $balanceAfter,
                'product_amount' => 0,
                'paid_amount' => $paidAmount,
                'reference' => $payment_data['transaction_id'] ?? null,
                'note' => 'Customer extra credit added after payment.',
                'metadata' => [
                    'package_name' => $additionalData['package_name'] ?? $subscription->package_name,
                    'payment_method' => $payment_data['payment_method'] ?? null,
                    'payment_amount' => $payment_data['payment_amount'] ?? null,
                    'currency_code' => $payment_data['currency_code'] ?? null,
                    'rate' => $additionalData['rate'] ?? null,
                    'requested_credit_amount' => $additionalData['requested_credit_amount'] ?? null,
                ],
            ]);
        });
    }
}

if (!function_exists('customer_activation_invoice_payment_success')) {
    function customer_activation_invoice_payment_success($payment_data): void
    {
        if (!isset($payment_data) || ($payment_data['is_paid'] ?? 0) != 1) {
            return;
        }

        $additionalData = json_decode($payment_data['additional_data'] ?? '{}', true);
        $invoiceId = (int) ($additionalData['activation_invoice_id'] ?? $payment_data['attribute_id'] ?? 0);

        if (!$invoiceId) {
            return;
        }

        session()->put('payment_mode', $additionalData['payment_mode'] ?? 'web');

        $invoice = CustomerActivationInvoice::where('id', $invoiceId)->first();
        if (!$invoice) {
            return;
        }

        app(CustomerActivationInvoiceService::class)->markInvoicePaidAndReleaseOrder($invoice, $payment_data);
        session()->forget('customer_activation_invoice_id');
        session()->forget('customer_activation_hold_message');
    }
}

if (!function_exists('customer_activation_invoice_payment_fail')) {
    function customer_activation_invoice_payment_fail($payment_data): void
    {

    }
}

if (!function_exists('post_purchase_invoice_payment_success')) {
    /**
     * The gateway callback settles only the external portion of the second
     * invoice. Insurance-wallet usage is applied separately and is never
     * accepted as a tax payment.
     */
    function post_purchase_invoice_payment_success($paymentData): void
    {
        if (! isset($paymentData) || ($paymentData['is_paid'] ?? 0) != 1) {
            return;
        }

        $additionalData = json_decode($paymentData['additional_data'] ?? '{}', true);
        $invoiceId = (int) ($additionalData['post_purchase_invoice_id'] ?? $paymentData['attribute_id'] ?? 0);
        $customerId = (int) ($additionalData['customer_id'] ?? $paymentData['payer_id'] ?? 0);
        $invoice = PostPurchaseInvoice::query()
            ->whereKey($invoiceId)
            ->where('customer_id', $customerId)
            ->first();
        if (! $invoice || ! $invoice->isPayable()) {
            return;
        }

        try {
            app(PostPurchaseInvoiceService::class)->markExternalPaymentPaid(
                $invoice,
                (float) ($additionalData['external_amount_due'] ?? $paymentData['payment_amount'] ?? 0),
                (string) ($paymentData['payment_method'] ?? 'digital_payment'),
                (string) ($paymentData['transaction_id'] ?? $paymentData['id'] ?? '')
            );
        } catch (\DomainException) {
            // Payment providers can retry success callbacks. A completed or
            // duplicate callback is deliberately ignored here.
        }
    }
}

if (!function_exists('post_purchase_invoice_payment_fail')) {
    function post_purchase_invoice_payment_fail($paymentData): void
    {
        // The invoice stays payable. No payment or insurance balance is
        // altered on gateway failure, so the customer can safely retry.
    }
}

if (!function_exists('customer_extra_credit_payment_fail')) {
    function customer_extra_credit_payment_fail($payment_data): void
    {

    }
}

if (!function_exists('seller_insurance_payment_success')) {
    function seller_insurance_payment_success($paymentData): void
    {
        if (!isset($paymentData) || ($paymentData['is_paid'] ?? 0) != 1) {
            return;
        }

        $additionalData = json_decode($paymentData['additional_data'] ?? '{}', true);
        $insuranceId = (int) ($additionalData['seller_insurance_id'] ?? $paymentData['attribute_id'] ?? 0);
        $sellerId = (int) ($additionalData['seller_id'] ?? $paymentData['payer_id'] ?? 0);
        if (!$insuranceId || !$sellerId) {
            return;
        }

        $insurance = SellerInsurance::query()
            ->where('id', $insuranceId)
            ->where('seller_id', $sellerId)
            ->first();
        if (!$insurance) {
            return;
        }

        $paymentPayload = is_object($paymentData) && method_exists($paymentData, 'toArray')
            ? $paymentData->toArray()
            : (array) $paymentData;
        app(SellerInsuranceService::class)->markPaid($insurance, $paymentPayload);
    }
}

if (!function_exists('seller_insurance_payment_fail')) {
    function seller_insurance_payment_fail($paymentData): void
    {
        $additionalData = json_decode($paymentData['additional_data'] ?? '{}', true);
        $insuranceId = (int) ($additionalData['seller_insurance_id'] ?? $paymentData['attribute_id'] ?? 0);
        $sellerId = (int) ($additionalData['seller_id'] ?? $paymentData['payer_id'] ?? 0);
        $insurance = SellerInsurance::query()
            ->where('id', $insuranceId)
            ->where('seller_id', $sellerId)
            ->first();

        if ($insurance) {
            $paymentPayload = is_object($paymentData) && method_exists($paymentData, 'toArray')
                ? $paymentData->toArray()
                : (array) $paymentData;
            app(SellerInsuranceService::class)->recordPaymentFailure($insurance, $paymentPayload);
        }
    }
}

if (!function_exists('seller_order_insurance_payment_success')) {
    function seller_order_insurance_payment_success($paymentData): void
    {
        if (($paymentData['is_paid'] ?? 0) != 1) return;
        $data = json_decode($paymentData['additional_data'] ?? '{}', true);
        $insurance = SellerOrderInsurance::query()->where('id', (int) ($data['seller_order_insurance_id'] ?? $paymentData['attribute_id'] ?? 0))
            ->where('seller_id', (int) ($data['seller_id'] ?? $paymentData['payer_id'] ?? 0))->first();
        if (! $insurance) return;
        app(SellerOrderInsuranceService::class)->markPaid($insurance, (string) ($paymentData['payment_method'] ?? 'digital_payment'), (string) ($paymentData['transaction_id'] ?? $paymentData['id'] ?? ''), null, null);
    }
}

if (!function_exists('seller_order_insurance_payment_fail')) {
    function seller_order_insurance_payment_fail($paymentData): void
    {
        $data = json_decode($paymentData['additional_data'] ?? '{}', true);
        $insurance = SellerOrderInsurance::query()->where('id', (int) ($data['seller_order_insurance_id'] ?? $paymentData['attribute_id'] ?? 0))
            ->where('seller_id', (int) ($data['seller_id'] ?? $paymentData['payer_id'] ?? 0))->first();
        if ($insurance) app(SellerOrderInsuranceService::class)->recordPaymentFailure($insurance, (array) $paymentData);
    }
}

if (!function_exists('seller_package_payment_success')) {
    function seller_package_payment_success($paymentData): void
    {
        if (! isset($paymentData) || ($paymentData['is_paid'] ?? 0) != 1) {
            return;
        }

        $additionalData = json_decode($paymentData['additional_data'] ?? '{}', true);
        $subscriptionId = (int) ($additionalData['seller_package_subscription_id'] ?? $paymentData['attribute_id'] ?? 0);
        $sellerId = (int) ($additionalData['seller_id'] ?? $paymentData['payer_id'] ?? 0);
        $subscription = SellerPackageSubscription::query()
            ->where('id', $subscriptionId)
            ->where('seller_id', $sellerId)
            ->first();
        if (! $subscription) {
            return;
        }

        $paymentPayload = is_object($paymentData) && method_exists($paymentData, 'toArray')
            ? $paymentData->toArray()
            : (array) $paymentData;
        app(SellerPackagePurchaseService::class)->markPaid($subscription, $paymentPayload);
    }
}

if (!function_exists('seller_package_payment_fail')) {
    function seller_package_payment_fail($paymentData): void
    {
        $additionalData = json_decode($paymentData['additional_data'] ?? '{}', true);
        $subscriptionId = (int) ($additionalData['seller_package_subscription_id'] ?? $paymentData['attribute_id'] ?? 0);
        $sellerId = (int) ($additionalData['seller_id'] ?? $paymentData['payer_id'] ?? 0);
        $subscription = SellerPackageSubscription::query()
            ->where('id', $subscriptionId)
            ->where('seller_id', $sellerId)
            ->first();

        if ($subscription) {
            $paymentPayload = is_object($paymentData) && method_exists($paymentData, 'toArray')
                ? $paymentData->toArray()
                : (array) $paymentData;
            app(SellerPackagePurchaseService::class)->recordPaymentFailure($subscription, $paymentPayload);
        }
    }
}

if (!function_exists('seller_balance_deposit_payment_success')) {
    function seller_balance_deposit_payment_success($paymentData): void
    {
        if (!isset($paymentData) || ($paymentData['is_paid'] ?? 0) != 1) return;
        $additional = json_decode($paymentData['additional_data'] ?? '{}', true);
        $depositId = (int) ($additional['seller_balance_deposit_id'] ?? $paymentData['attribute_id'] ?? 0);
        $sellerId = (int) ($additional['seller_id'] ?? $paymentData['payer_id'] ?? 0);
        $deposit = SellerBalanceDeposit::query()->whereKey($depositId)->where('seller_id', $sellerId)->first();
        if (!$deposit) return;
        $payload = is_object($paymentData) && method_exists($paymentData, 'toArray') ? $paymentData->toArray() : (array) $paymentData;
        app(SellerBalanceDepositService::class)->markPaid($deposit, $payload);
    }
}

if (!function_exists('seller_balance_deposit_payment_fail')) {
    function seller_balance_deposit_payment_fail($paymentData): void
    {
        // Failed digital payments remain pending and can be retried; no ledger movement is posted.
    }
}

if (!function_exists('config_settings')) {
    function config_settings($key, $settings_type)
    {
        try {
            $config = DB::table('addon_settings')->where('key_name', $key)
                ->where('settings_type', $settings_type)->first();
        } catch (Exception $exception) {
            return null;
        }
        return (isset($config)) ? $config : null;
    }
}

if (!function_exists('getCheckAddonPublishedStatus')) {
    function getCheckAddonPublishedStatus(string $moduleName): int
    {
        try {
            if (file_exists(base_path("Modules/{$moduleName}/Addon/info.php"))) {
                $full_data = include(base_path("Modules/{$moduleName}/Addon/info.php"));
                return $full_data['is_published'] == 1 ? 1 : 0;
            }
        } catch (Exception $exception) {
        }
        return 0;
    }
}

if (!function_exists('getTaxModuleSystemTypesConfig')) {
    function getTaxModuleSystemTypesConfig($getTaxVatList = true, $tax_payer = 'vendor'): array
    {
        $cacheKey = "tax_system_type_{$tax_payer}_" . ($getTaxVatList ? 'with_vat' : 'no_vat');

        $cacheKeys = Cache::get('cache_tax_system_types_and_config', []);
        if (!in_array($cacheKey, $cacheKeys)) {
            $cacheKeys[] = $cacheKey;
            Cache::put('cache_tax_system_types_and_config', $cacheKeys, 60 * 60 * 24 * 7);
        }

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($getTaxVatList, $tax_payer) {
            if (getCheckAddonPublishedStatus('TaxModule')) {
                $systemTaxVat = SystemTaxSetup::where('is_active', 1)
                    ->with(['additionalData' => function ($query) {
                        return $query->where('is_active', 1);
                    }])
                    ->where('tax_payer', $tax_payer)
                    ->where('is_default', 1)
                    ->first();

                if (!$systemTaxVat) {
                    $systemTaxVat = SystemTaxSetup::create([
                        'tax_type' => 'order_wise',
                        'country_code' => null,
                        'tax_payer' => 'vendor',
                        'is_default' => true,
                        'is_active' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    return [
                        'SystemTaxVat' => $systemTaxVat ?? null,
                        'SystemTaxVatType' => $systemTaxVat?->tax_type ?? 'order_wise',
                        'is_included' => $systemTaxVat?->is_included ?? 0,
                        'productWiseTax' => false,
                        'categoryWiseTax' => false,
                        'taxVats' => []
                    ];
                }

                if ($getTaxVatList) {
                    $taxVats = Tax::where('is_active', 1)->where('is_default', 1)->get();
                }

                if ($systemTaxVat?->tax_type == 'product_wise') {
                    $productWiseTax = true;
                } elseif ($systemTaxVat?->tax_type == 'category_wise') {
                    $categoryWiseTax = true;
                }
            }

            return [
                'SystemTaxVat' => $systemTaxVat ?? null,
                'SystemTaxVatType' => $systemTaxVat?->tax_type ?? 'order_wise',
                'is_included' => $systemTaxVat?->is_included ?? 0,
                'productWiseTax' => $productWiseTax ?? false,
                'categoryWiseTax' => $categoryWiseTax ?? false,
                'taxVats' => $taxVats ?? []
            ];
        });
    }
}

if (!function_exists('getModuleDynamicAsset')) {
    function getModuleDynamicAsset(string $path): string
    {
        if (getModuleAssetsProcessingDirectory() == 'public') {
            $position = strpos($path, 'public/');
            $result = $path;
            if ($position === 0) {
                $result = preg_replace('/public/', '', $path, 1);
            }
        } else {
            $result = $path;
        }
        return asset($result);
    }
}

if (!function_exists('getModuleDynamicStorage')) {
    function getModuleDynamicStorage(string $path): string
    {
        if (getModuleAssetsProcessingDirectory() == 'public') {
            $result = str_replace('storage/app/public', 'storage', $path);
        } else {
            $result = $path;
        }
        return asset($result);
    }
}

if (!function_exists('getModuleAssetsProcessingDirectory')) {
    function getModuleAssetsProcessingDirectory(): string
    {
        $cacheKey = 'SYSTEM_DOMAIN_POINTED_DIRECTORY_' . md5($_SERVER['SCRIPT_FILENAME']);
        return Cache::rememberForever($cacheKey, function () {
            $scriptPath = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
            $basePath = realpath(base_path());
            $publicPath = realpath(public_path());

            if ($scriptPath === $publicPath) {
                return 'public';
            } elseif ($scriptPath === $basePath) {
                return 'root';
            }
            return 'unknown';
        });
    }
}
