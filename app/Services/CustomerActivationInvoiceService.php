<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\CustomerActivationInvoice;
use App\Models\CustomerPurchaseLimitTransaction;
use App\Models\CustomerPurchasePackage;
use App\Models\CustomerPurchasePackageSubscription;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerActivationInvoiceService
{
    public function __construct(
        private readonly CustomerPurchaseLimitService $purchaseLimitService,
    ) {
    }

    public function validateProductPaymentAccess(
        mixed $customer,
        Collection|EloquentCollection|array|null $cartList = null,
        bool $allowActivationInvoice = false
    ): array {
        if (!$this->purchaseLimitService->isFeatureEnabled()) {
            return [
                'status' => 1,
                'code' => 'customer_purchase_packages_suspended',
                'message' => translate('purchase_limit_available'),
                'assessment' => $this->purchaseLimitService->getCheckoutLimitAssessment($customer, $cartList),
                'activation_required' => false,
            ];
        }

        $assessment = $this->purchaseLimitService->getCheckoutLimitAssessment($customer, $cartList);

        if (!$assessment['has_active_package']) {
            if ($allowActivationInvoice) {
                return [
                    'status' => 1,
                    'code' => 'activation_invoice_required_after_product_payment',
                    'message' => translate('activation_invoice_will_be_required_after_product_payment'),
                    'assessment' => $assessment,
                    'activation_required' => true,
                ];
            }

            return $this->purchaseLimitService->validateCheckoutAccess($customer, $cartList);
        }

        if ($assessment['shortage'] > 0) {
            return $this->purchaseLimitService->validateCheckoutAccess($customer, $cartList);
        }

        if ($this->isMonthlyInsuranceExpired($customer)) {
            return [
                'status' => 0,
                'code' => 'monthly_insurance_required',
                'message' => translate('monthly_insurance_payment_is_required_before_new_checkout'),
                'assessment' => $assessment,
                'activation_required' => true,
            ];
        }

        return [
            'status' => 1,
            'code' => 'customer_purchase_limit_available',
            'message' => translate('purchase_limit_available'),
            'assessment' => $assessment,
            'activation_required' => false,
        ];
    }

    public function shouldHoldPaidOrderForActivation(mixed $customer): bool
    {
        if (!$this->purchaseLimitService->isFeatureEnabled()) {
            return false;
        }

        $assessment = $this->purchaseLimitService->getLimitSummary($customer);

        return !$assessment['has_active_package'];
    }

    public function createForPaidOrderGroup(array $orderIds): ?CustomerActivationInvoice
    {
        $orderIds = array_values(array_filter($orderIds));
        if (empty($orderIds)) {
            return null;
        }

        return DB::transaction(function () use ($orderIds) {
            $firstOrder = Order::with(['customer', 'details'])->whereIn('id', $orderIds)->oldest('id')->first();
            if (!$firstOrder || (int) $firstOrder->is_guest === 1 || !$firstOrder->customer_id) {
                return null;
            }

            $customer = $firstOrder->customer ?: User::find($firstOrder->customer_id);
            if (!$customer || !$this->shouldHoldPaidOrderForActivation($customer)) {
                return null;
            }

            $orderGroupId = $firstOrder->order_group_id;
            $existingInvoice = CustomerActivationInvoice::where('order_group_id', $orderGroupId)
                ->whereIn('status', ['pending', 'pending_package_assignment', 'pending_offline_review', 'paid'])
                ->latest('id')
                ->first();

            if ($existingInvoice) {
                $this->markOrdersAsActivationPending($orderIds);
                return $existingInvoice;
            }

            $orders = Order::with('details')
                ->where('order_group_id', $orderGroupId)
                ->where('customer_id', $firstOrder->customer_id)
                ->lockForUpdate()
                ->get();

            $productTotal = (float) $orders->sum(fn (Order $order) => $this->purchaseLimitService->getOrderProductLimitAmount($order));
            $package = $this->getRecommendedPackage((int) $firstOrder->customer_id, $productTotal);
            $insurance = $this->getInsuranceLine($customer);
            $packagePrice = $package ? (float) $package->package_price : 0.0;
            $packageLimit = $package ? (float) $package->purchase_limit : 0.0;
            $totalAmount = round($packagePrice + $insurance['amount'], 2);

            $invoice = CustomerActivationInvoice::create([
                'invoice_no' => $this->generateInvoiceNo(),
                'customer_id' => $firstOrder->customer_id,
                'order_id' => $firstOrder->id,
                'order_group_id' => $orderGroupId,
                'customer_purchase_package_id' => $package?->id,
                'package_name' => $package?->name,
                'package_price' => $packagePrice,
                'package_purchase_limit' => $packageLimit,
                'insurance_original_amount' => $insurance['original_amount'],
                'insurance_discount_amount' => $insurance['discount_amount'],
                'insurance_discount_type' => $insurance['discount_type'],
                'insurance_amount' => $insurance['amount'],
                'total_amount' => $totalAmount,
                'paid_amount' => 0,
                'currency_code' => $this->getDefaultCurrencyCode(),
                'payment_status' => 'unpaid',
                'status' => $package ? 'pending' : 'pending_package_assignment',
                'insurance_period_start' => $insurance['period_start'],
                'insurance_period_end' => $insurance['period_end'],
                'metadata' => [
                    'order_ids' => $orders->pluck('id')->values()->all(),
                    'product_total' => round($productTotal, 2),
                    'package_missing' => !$package,
                    'created_from' => 'product_payment_success',
                ],
            ]);

            $this->markOrdersAsActivationPending($orders->pluck('id')->all());

            return $invoice;
        });
    }

    public function getActivationHoldMessage(?CustomerActivationInvoice $invoice = null): string
    {
        $message = getWebConfig(name: 'customer_activation_hold_message')
            ?: 'Payment received successfully. Your order is pending until the monthly insurance and package activation invoice is paid.';

        if ($invoice && (float) $invoice->total_amount > 0) {
            $message .= ' ' . translate('Activation_invoice_total') . ': '
                . setCurrencySymbol(amount: usdToDefaultCurrency(amount: $invoice->total_amount), currencyCode: getCurrencyCode()) . '.';
        }

        return $message;
    }

    public function getPendingInvoiceForCustomer(int $customerId): ?CustomerActivationInvoice
    {
        return CustomerActivationInvoice::with(['package', 'order'])
            ->where('customer_id', $customerId)
            ->where('payment_status', 'unpaid')
            ->whereIn('status', ['pending', 'pending_package_assignment', 'pending_offline_review'])
            ->latest('id')
            ->first();
    }

    public function assignPackageToInvoice(int $customerId, int $invoiceId, int $packageId): array
    {
        return DB::transaction(function () use ($customerId, $invoiceId, $packageId) {
            $invoice = CustomerActivationInvoice::where('id', $invoiceId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            if (!$invoice || $invoice->payment_status !== 'unpaid') {
                return ['status' => 0, 'message' => 'activation_invoice_not_found'];
            }

            if (!in_array($invoice->status, ['pending', 'pending_package_assignment'], true)) {
                return ['status' => 0, 'message' => 'activation_invoice_not_available'];
            }

            $package = CustomerPurchasePackage::where('id', $packageId)
                ->where('status', 1)
                ->where(function ($query) use ($customerId) {
                    $query->where('is_custom', 0)->orWhere('customer_id', $customerId);
                })
                ->first();

            $productTotal = (float) data_get($invoice->metadata, 'product_total', 0);
            if (!$package || (float) $package->purchase_limit < $productTotal) {
                return ['status' => 0, 'message' => 'selected_package_does_not_cover_order'];
            }

            $invoice->update([
                'customer_purchase_package_id' => $package->id,
                'package_name' => $package->name,
                'package_price' => (float) $package->package_price,
                'package_purchase_limit' => (float) $package->purchase_limit,
                'total_amount' => round((float) $package->package_price + (float) $invoice->insurance_amount, 2),
                'status' => 'pending',
                'metadata' => array_merge($invoice->metadata ?? [], [
                    'package_missing' => false,
                    'selected_from_app' => true,
                    'selected_package_id' => $package->id,
                ]),
            ]);

            return ['status' => 1, 'invoice' => $invoice->fresh()];
        });
    }

    public function createStandaloneInvoiceForPackage(mixed $customer, int $packageId): array
    {
        return DB::transaction(function () use ($customer, $packageId) {
            $package = CustomerPurchasePackage::where('id', $packageId)
                ->where('status', 1)
                ->where(function ($query) use ($customer) {
                    $query->where('is_custom', 0)->orWhere('customer_id', $customer->id);
                })
                ->first();

            if (!$package || (float) $package->package_price <= 0 || (float) $package->purchase_limit <= 0) {
                return ['status' => 0, 'message' => 'customer_purchase_package_not_found'];
            }

            $existingInvoice = $this->getPendingInvoiceForCustomer((int) $customer->id);
            if ($existingInvoice) {
                return $this->assignPackageToInvoice((int) $customer->id, (int) $existingInvoice->id, $packageId);
            }

            $insurance = $this->getInsuranceLine($customer);
            $invoice = CustomerActivationInvoice::create([
                'invoice_no' => $this->generateInvoiceNo(),
                'customer_id' => $customer->id,
                'customer_purchase_package_id' => $package->id,
                'package_name' => $package->name,
                'package_price' => (float) $package->package_price,
                'package_purchase_limit' => (float) $package->purchase_limit,
                'insurance_original_amount' => $insurance['original_amount'],
                'insurance_discount_amount' => $insurance['discount_amount'],
                'insurance_discount_type' => $insurance['discount_type'],
                'insurance_amount' => $insurance['amount'],
                'total_amount' => round((float) $package->package_price + (float) $insurance['amount'], 2),
                'paid_amount' => 0,
                'currency_code' => $this->getDefaultCurrencyCode(),
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'insurance_period_start' => $insurance['period_start'],
                'insurance_period_end' => $insurance['period_end'],
                'metadata' => [
                    'product_total' => 0,
                    'package_missing' => false,
                    'created_from' => 'standalone_package_purchase',
                ],
            ]);

            return ['status' => 1, 'invoice' => $invoice];
        });
    }

    public function handleProductPaymentApproved(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::with('details')
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedOrder) {
                return ['status' => 0, 'message' => 'order_not_found'];
            }

            if ($lockedOrder->payment_status !== 'paid') {
                return ['status' => 0, 'message' => 'product_payment_not_approved'];
            }

            $invoice = CustomerActivationInvoice::query()
                ->where('customer_id', $lockedOrder->customer_id)
                ->where('order_group_id', $lockedOrder->order_group_id)
                ->whereIn('status', ['pending', 'pending_package_assignment', 'pending_offline_review', 'paid'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                $summary = $this->purchaseLimitService->getLimitSummary($lockedOrder->customer_id);
                if (!($summary['has_active_package'] ?? false)) {
                    $invoice = $this->createForPaidOrderGroup([$lockedOrder->id]);
                }
            }

            if ($invoice && $invoice->payment_status !== 'paid') {
                $lockedOrder->update([
                    'order_status' => 'pending',
                    'activation_status' => 'activation_pending',
                    'activation_pending_at' => $lockedOrder->activation_pending_at ?: now(),
                ]);

                return [
                    'status' => 1,
                    'message' => 'activation_payment_pending',
                    'invoice' => $invoice,
                    'order' => $lockedOrder->fresh(),
                ];
            }

            $lockedOrder->update([
                'order_status' => 'confirmed',
                'activation_status' => $invoice ? 'activation_completed' : 'not_required',
                'activation_completed_at' => $invoice ? now() : null,
                'is_pause' => 0,
            ]);

            $debit = $this->purchaseLimitService->debitOrderLimit($lockedOrder->fresh('details'));

            return [
                'status' => 1,
                'message' => 'product_payment_approved',
                'invoice' => $invoice,
                'debit' => $debit,
                'order' => $lockedOrder->fresh(),
            ];
        });
    }

    public function markInvoicePaidAndReleaseOrder(CustomerActivationInvoice $invoice, array $paymentData): array
    {
        return DB::transaction(function () use ($invoice, $paymentData) {
            $invoice = CustomerActivationInvoice::where('id', $invoice->id)->lockForUpdate()->first();

            if (!$invoice) {
                return ['status' => 0, 'message' => 'invoice_not_found'];
            }

            if ($invoice->payment_status === 'paid' && $invoice->status === 'paid') {
                return ['status' => 1, 'message' => 'already_paid', 'invoice' => $invoice];
            }

            if (!$invoice->customer_purchase_package_id || (float) $invoice->package_purchase_limit <= 0) {
                return ['status' => 0, 'message' => 'invoice_package_missing'];
            }

            if ((float) $invoice->total_amount <= 0) {
                return ['status' => 0, 'message' => 'invalid_invoice_amount'];
            }

            $existingTransaction = CustomerPurchaseLimitTransaction::where('payment_request_id', $paymentData['id'] ?? null)
                ->where('transaction_type', 'activation_invoice_payment')
                ->first();

            if ($existingTransaction) {
                return ['status' => 1, 'message' => 'already_paid', 'invoice' => $invoice];
            }

            CustomerPurchasePackageSubscription::where('customer_id', $invoice->customer_id)
                ->where('status', 'active')
                ->update([
                    'status' => 'replaced',
                    'cancelled_at' => now(),
                ]);

            $subscription = CustomerPurchasePackageSubscription::create([
                'customer_id' => $invoice->customer_id,
                'customer_purchase_package_id' => $invoice->customer_purchase_package_id,
                'package_name' => $invoice->package_name,
                'paid_package_price' => (float) $invoice->package_price,
                'package_purchase_limit' => (float) $invoice->package_purchase_limit,
                'used_purchase_limit' => 0,
                'extra_credit_limit' => 0,
                'admin_adjustment_limit' => 0,
                'available_purchase_limit' => (float) $invoice->package_purchase_limit,
                'status' => 'active',
                'payment_status' => 'paid',
                'payment_method' => $paymentData['payment_method'] ?? null,
                'payment_reference' => $paymentData['transaction_id'] ?? null,
                'starts_at' => now(),
                'activated_at' => now(),
                'metadata' => [
                    'activation_invoice_id' => $invoice->id,
                    'payment_request_id' => $paymentData['id'] ?? null,
                    'payment_amount' => $paymentData['payment_amount'] ?? null,
                    'currency_code' => $paymentData['currency_code'] ?? null,
                ],
            ]);

            CustomerPurchaseLimitTransaction::create([
                'customer_id' => $invoice->customer_id,
                'customer_purchase_package_subscription_id' => $subscription->id,
                'customer_purchase_package_id' => $invoice->customer_purchase_package_id,
                'payment_request_id' => $paymentData['id'] ?? null,
                'order_id' => $invoice->order_id,
                'transaction_id' => (string) \Illuminate\Support\Str::uuid(),
                'transaction_type' => 'activation_invoice_payment',
                'credit' => (float) $invoice->package_purchase_limit,
                'debit' => 0,
                'balance_after' => (float) $invoice->package_purchase_limit,
                'product_amount' => 0,
                'paid_amount' => (float) $invoice->total_amount,
                'reference' => $paymentData['transaction_id'] ?? null,
                'note' => 'Customer activation invoice paid and package activated.',
                'metadata' => [
                    'activation_invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'insurance_amount' => (float) $invoice->insurance_amount,
                    'package_price' => (float) $invoice->package_price,
                ],
            ]);

            $invoice->update([
                'customer_purchase_package_subscription_id' => $subscription->id,
                'payment_request_id' => $paymentData['id'] ?? null,
                'paid_amount' => (float) $invoice->total_amount,
                'payment_method' => $paymentData['payment_method'] ?? null,
                'payment_reference' => $paymentData['transaction_id'] ?? null,
                'payment_status' => 'paid',
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $customer = User::where('id', $invoice->customer_id)->lockForUpdate()->first();
            if ($customer && $invoice->insurance_period_end) {
                $customer->update([
                    'monthly_insurance_started_at' => $customer->monthly_insurance_started_at ?: ($invoice->insurance_period_start ?: now()),
                    'monthly_insurance_paid_until' => $invoice->insurance_period_end,
                    'monthly_insurance_last_paid_at' => now(),
                ]);
            }

            if ($invoice->order_group_id) {
                $orders = Order::where('order_group_id', $invoice->order_group_id)
                    ->where('customer_id', $invoice->customer_id)
                    ->lockForUpdate()
                    ->get();

                foreach ($orders as $order) {
                    if ($order->payment_status !== 'paid') {
                        continue;
                    }

                    $order->update([
                        'order_status' => 'confirmed',
                        'activation_status' => 'activation_completed',
                        'activation_completed_at' => now(),
                    ]);

                    $this->purchaseLimitService->debitOrderLimit($order->fresh('details'));
                }
            }

            return ['status' => 1, 'message' => 'paid', 'invoice' => $invoice->fresh(), 'subscription' => $subscription];
        });
    }

    private function getRecommendedPackage(int $customerId, float $productTotal): ?CustomerPurchasePackage
    {
        return CustomerPurchasePackage::query()
            ->where('status', 1)
            ->where('purchase_limit', '>=', $productTotal)
            ->where(function ($query) use ($customerId) {
                $query->whereNull('customer_id')
                    ->orWhere('customer_id', $customerId);
            })
            ->orderBy('purchase_limit')
            ->orderBy('package_price')
            ->first();
    }

    private function getInsuranceLine(mixed $customer): array
    {
        $insuranceEnabled = (bool) getWebConfig(name: 'customer_monthly_insurance_status');
        $originalAmount = $insuranceEnabled ? (float) (getWebConfig(name: 'customer_monthly_insurance_amount') ?? 0) : 0.0;
        $discountType = getWebConfig(name: 'customer_monthly_insurance_first_discount_type') ?: 'none';
        $discountValue = (float) (getWebConfig(name: 'customer_monthly_insurance_first_discount_value') ?? 0);
        $periodDays = max((int) (getWebConfig(name: 'customer_monthly_insurance_period_days') ?? 30), 1);
        $discountAmount = 0.0;

        if (!$customer->monthly_insurance_started_at && $originalAmount > 0) {
            $discountAmount = match ($discountType) {
                'free' => $originalAmount,
                'fixed' => min($discountValue, $originalAmount),
                'percentage' => min(($originalAmount * min($discountValue, 100)) / 100, $originalAmount),
                default => 0.0,
            };
        }

        $amount = max($originalAmount - $discountAmount, 0);
        $periodStart = Carbon::now();

        return [
            'original_amount' => round($originalAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'discount_type' => $discountType,
            'amount' => round($amount, 2),
            'period_start' => $insuranceEnabled ? $periodStart : null,
            'period_end' => $insuranceEnabled ? $periodStart->copy()->addDays($periodDays) : null,
        ];
    }

    private function isMonthlyInsuranceExpired(mixed $customer): bool
    {
        if (!(bool) getWebConfig(name: 'customer_monthly_insurance_status')) {
            return false;
        }

        $customer = is_object($customer) ? $customer : User::find($customer);
        if (!$customer || !$customer->monthly_insurance_started_at) {
            return false;
        }

        return !$customer->monthly_insurance_paid_until || Carbon::parse($customer->monthly_insurance_paid_until)->lt(Carbon::now());
    }

    private function markOrdersAsActivationPending(array $orderIds): void
    {
        Order::whereIn('id', $orderIds)->update([
            'order_status' => 'pending',
            'activation_status' => 'activation_pending',
            'activation_pending_at' => now(),
        ]);
    }

    private function generateInvoiceNo(): string
    {
        do {
            $invoiceNo = 'ACT-' . now()->format('YmdHis') . '-' . random_int(1000, 9999);
        } while (CustomerActivationInvoice::where('invoice_no', $invoiceNo)->exists());

        return $invoiceNo;
    }

    private function getDefaultCurrencyCode(): string
    {
        $currencyId = getWebConfig(name: 'system_default_currency');

        return Currency::where('id', $currencyId)->value('code') ?: 'USD';
    }
}
