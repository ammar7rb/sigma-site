<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\CustomerActivationInvoice;
use App\Models\CustomerPurchasePackage;
use App\Models\OfflinePaymentMethod;
use App\Services\CustomerActivationInvoiceService;
use App\Utils\Convert;
use App\Services\CustomerPurchaseLimitService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function App\Utils\payment_gateways;

class CustomerPurchasePackageController extends Controller
{
    use Payment, PaymentGatewayTrait, FileManagerTrait;

    public function index(CustomerPurchaseLimitService $purchaseLimitService, CustomerActivationInvoiceService $activationInvoiceService): View
    {
        $customer = auth('customer')->user();
        $paymentGatewayList = payment_gateways();
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');
        $digitalPaymentAvailable = count($paymentGatewayList) > 0 && ($digitalPaymentStatus['status'] ?? 0);
        $offlinePaymentMethods = OfflinePaymentMethod::query()->where('status', 1)->get();
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $offlinePaymentAvailable = ($offlinePaymentStatus['status'] ?? 0) && $offlinePaymentMethods->count() > 0;
        $packages = CustomerPurchasePackage::query()
            ->where('status', 1)
            ->where(function ($query) use ($customer) {
                $query->where('is_custom', 0)
                    ->orWhere('customer_id', $customer->id);
            })
            ->orderBy('is_custom', 'desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view(VIEW_FILE_NAMES['customer_purchase_packages'], [
            'packages' => $packages,
            'limitSummary' => $purchaseLimitService->getLimitSummary($customer),
            'extraCreditSettings' => $purchaseLimitService->getExtraCreditSettings(),
            'pendingActivationInvoice' => $activationInvoiceService->getPendingInvoiceForCustomer($customer->id),
            'paymentGatewayList' => $paymentGatewayList,
            'digitalPaymentAvailable' => $digitalPaymentAvailable,
            'offlinePaymentMethods' => $offlinePaymentMethods,
            'offlinePaymentAvailable' => $offlinePaymentAvailable,
            'paymentAvailable' => $digitalPaymentAvailable || $offlinePaymentAvailable,
        ]);
    }

    public function payActivationInvoice(Request $request, int $id): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|string',
            'payment_platform' => 'required|string',
            'external_redirect_link' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                Toastr::error(translate($error));
            }
            return back();
        }

        $customer = auth('customer')->user();
        $invoice = CustomerActivationInvoice::where('id', $id)
            ->where('customer_id', $customer->id)
            ->where('payment_status', 'unpaid')
            ->whereIn('status', ['pending', 'pending_package_assignment'])
            ->first();

        if (!$invoice) {
            Toastr::error(translate('activation_invoice_not_found'));
            return back();
        }

        if ($invoice->status === 'pending_package_assignment' || !$invoice->customer_purchase_package_id) {
            Toastr::error(translate('activation_invoice_needs_a_valid_package_before_payment'));
            return back();
        }

        if ((float) $invoice->total_amount <= 0) {
            Toastr::error(translate('invalid_activation_invoice_amount'));
            return back();
        }

        $paymentAmount = (float) $invoice->total_amount;
        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            $currentCurrency = $request['current_currency_code'] ?? session('currency_code');
            $currencyCode = $this->getPaymentGatewayCurrencyCode(key: $request['payment_method'], currentCurrency: $currentCurrency);
            $paymentAmount = usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: $paymentAmount);
        } else {
            $defaultCurrencyId = getWebConfig(name: 'system_default_currency');
            $currencyCode = Currency::find($defaultCurrencyId)->code;
        }

        $additionalData = [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
            'payment_mode' => $request['payment_platform'] ?: 'web',
            'payment_request_from' => 'web',
            'customer_id' => $customer->id,
            'activation_invoice_id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'order_group_id' => $invoice->order_group_id,
            'package_id' => $invoice->customer_purchase_package_id,
            'package_name' => $invoice->package_name,
            'package_price' => (float) $invoice->package_price,
            'purchase_limit' => (float) $invoice->package_purchase_limit,
            'insurance_amount' => (float) $invoice->insurance_amount,
            'total_amount' => (float) $invoice->total_amount,
        ];

        $payer = new Payer(
            trim($customer->f_name . ' ' . $customer->l_name) ?: $customer->name,
            $customer->email,
            $customer->phone,
            ''
        );

        $paymentInfo = new PaymentInfo(
            success_hook: 'customer_activation_invoice_payment_success',
            failure_hook: 'customer_activation_invoice_payment_fail',
            currency_code: $currencyCode,
            payment_method: $request['payment_method'],
            payment_platform: $request['payment_platform'],
            payer_id: $customer->id,
            receiver_id: '100',
            additional_data: $additionalData,
            payment_amount: $paymentAmount,
            external_redirect_link: $request['payment_platform'] == 'web' ? route('customer.purchase-package.index') : null,
            attribute: 'customer_activation_invoice',
            attribute_id: $invoice->id
        );

        $redirectLink = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));

        if (!$redirectLink) {
            Toastr::error(translate('payment_method_is_not_available'));
            return back();
        }

        return redirect($redirectLink);
    }

    public function payActivationInvoiceByOfflinePayment(Request $request, int $id): RedirectResponse
    {
        $customer = auth('customer')->user();
        $invoice = CustomerActivationInvoice::where('id', $id)
            ->where('customer_id', $customer->id)
            ->where('payment_status', 'unpaid')
            ->whereIn('status', ['pending', 'pending_offline_review'])
            ->first();

        if (!$invoice) {
            Toastr::error(translate('activation_invoice_not_found'));
            return back();
        }

        if (!$invoice->customer_purchase_package_id || (float) $invoice->total_amount <= 0) {
            Toastr::error(translate('invalid_activation_invoice_amount'));
            return back();
        }

        return $this->submitOfflinePaymentReview($request, $invoice, 'offline-payment/activation-invoice-proof/');
    }

    public function purchase(Request $request, int $id): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|string',
            'payment_platform' => 'required|string',
            'external_redirect_link' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                Toastr::error(translate($error));
            }
            return back();
        }

        $customer = auth('customer')->user();
        $package = CustomerPurchasePackage::query()
            ->where('id', $id)
            ->where('status', 1)
            ->where(function ($query) use ($customer) {
                $query->where('is_custom', 0)
                    ->orWhere('customer_id', $customer->id);
            })
            ->first();

        if (!$package) {
            Toastr::error(translate('customer_purchase_package_not_found'));
            return back();
        }

        if ((float) $package->package_price <= 0 || (float) $package->purchase_limit <= 0) {
            Toastr::error(translate('invalid_customer_purchase_package'));
            return back();
        }

        $paymentAmount = (float) $package->package_price;
        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            $currentCurrency = $request['current_currency_code'] ?? session('currency_code');
            $currencyCode = $this->getPaymentGatewayCurrencyCode(key: $request['payment_method'], currentCurrency: $currentCurrency);
            $paymentAmount = usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: $paymentAmount);
        } else {
            $defaultCurrencyId = getWebConfig(name: 'system_default_currency');
            $currencyCode = Currency::find($defaultCurrencyId)->code;
        }

        $additionalData = [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
            'payment_mode' => $request['payment_platform'] ?: 'web',
            'payment_request_from' => 'web',
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'package_name' => $package->name,
            'package_price' => (float) $package->package_price,
            'purchase_limit' => (float) $package->purchase_limit,
            'is_custom' => (bool) $package->is_custom,
        ];

        $payer = new Payer(
            trim($customer->f_name . ' ' . $customer->l_name) ?: $customer->name,
            $customer->email,
            $customer->phone,
            ''
        );

        $paymentInfo = new PaymentInfo(
            success_hook: 'customer_purchase_package_payment_success',
            failure_hook: 'customer_purchase_package_payment_fail',
            currency_code: $currencyCode,
            payment_method: $request['payment_method'],
            payment_platform: $request['payment_platform'],
            payer_id: $customer->id,
            receiver_id: '100',
            additional_data: $additionalData,
            payment_amount: $paymentAmount,
            external_redirect_link: $request['payment_platform'] == 'web' ? route('customer.purchase-package.index') : null,
            attribute: 'customer_purchase_package',
            attribute_id: $package->id
        );

        $receiverInfo = new Receiver('receiver_name', 'example.png');
        $redirectLink = $this->generate_link($payer, $paymentInfo, $receiverInfo);

        if (!$redirectLink) {
            Toastr::error(translate('payment_method_is_not_available'));
            return back();
        }

        return redirect($redirectLink);
    }

    public function purchaseByOfflinePayment(Request $request, int $id): RedirectResponse
    {
        $customer = auth('customer')->user();
        $package = CustomerPurchasePackage::query()
            ->where('id', $id)
            ->where('status', 1)
            ->where(function ($query) use ($customer) {
                $query->where('is_custom', 0)
                    ->orWhere('customer_id', $customer->id);
            })
            ->first();

        if (!$package) {
            Toastr::error(translate('customer_purchase_package_not_found'));
            return back();
        }

        if ((float) $package->package_price <= 0 || (float) $package->purchase_limit <= 0) {
            Toastr::error(translate('invalid_customer_purchase_package'));
            return back();
        }

        $invoice = CustomerActivationInvoice::create([
            'invoice_no' => $this->generateCustomerPackageInvoiceNo(),
            'customer_id' => $customer->id,
            'customer_purchase_package_id' => $package->id,
            'package_name' => $package->name,
            'package_price' => (float) $package->package_price,
            'package_purchase_limit' => (float) $package->purchase_limit,
            'insurance_original_amount' => 0,
            'insurance_discount_amount' => 0,
            'insurance_amount' => 0,
            'total_amount' => (float) $package->package_price,
            'paid_amount' => 0,
            'currency_code' => Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD',
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'metadata' => [
                'created_from' => 'customer_purchase_package',
                'is_custom' => (bool) $package->is_custom,
            ],
        ]);

        return $this->submitOfflinePaymentReview($request, $invoice, 'offline-payment/customer-package-proof/');
    }

    public function purchaseExtraCredit(Request $request, CustomerPurchaseLimitService $purchaseLimitService): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'credit_amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'payment_platform' => 'required|string',
            'external_redirect_link' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                Toastr::error(translate($error));
            }
            return back();
        }

        $paymentGatewayList = payment_gateways();
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');
        if (count($paymentGatewayList) <= 0 || !($digitalPaymentStatus['status'] ?? 0)) {
            Toastr::error(translate('payment_method_is_not_available'));
            return back();
        }

        $customer = auth('customer')->user();
        $activeSubscription = $purchaseLimitService->getActiveSubscription($customer);
        if (!$activeSubscription) {
            Toastr::error(translate('customer_does_not_have_an_active_purchase_package'));
            return back();
        }

        $currentCurrency = $request['current_currency_code'] ?? session('currency_code');
        if (!$currentCurrency) {
            $currentCurrency = Currency::find(getWebConfig(name: 'system_default_currency'))->code;
        }
        $requestedCreditAmount = Convert::usdPaymentModule($request['credit_amount'], $currentCurrency);
        $extraCredit = $purchaseLimitService->calculateExtraCreditPurchase($requestedCreditAmount);
        if (!$extraCredit['status']) {
            $messages = [
                'extra_credit_disabled' => translate('extra_credit_is_currently_disabled'),
                'extra_credit_maximum_amount_exceeded' => translate('extra_credit_maximum_amount_exceeded'),
                'invalid_extra_credit_rate' => translate('invalid_extra_credit_rate'),
            ];
            Toastr::error($messages[$extraCredit['reason']] ?? translate('invalid_extra_credit_amount'));
            return back();
        }

        $paymentAmount = (float) $extraCredit['payment_amount'];
        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            $currencyCode = $this->getPaymentGatewayCurrencyCode(key: $request['payment_method'], currentCurrency: $currentCurrency);
            $paymentAmount = usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: $paymentAmount);
        } else {
            $defaultCurrencyId = getWebConfig(name: 'system_default_currency');
            $currencyCode = Currency::find($defaultCurrencyId)->code;
        }

        $additionalData = [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
            'payment_mode' => $request['payment_platform'] ?: 'web',
            'payment_request_from' => 'web',
            'customer_id' => $customer->id,
            'subscription_id' => $activeSubscription->id,
            'package_id' => $activeSubscription->customer_purchase_package_id,
            'package_name' => $activeSubscription->package_name,
            'credit_amount' => (float) $extraCredit['credit_amount'],
            'paid_amount' => (float) $extraCredit['payment_amount'],
            'rate' => (float) ($extraCredit['settings']['rate'] ?? 0),
            'requested_credit_amount' => (float) $requestedCreditAmount,
        ];

        $payer = new Payer(
            trim($customer->f_name . ' ' . $customer->l_name) ?: $customer->name,
            $customer->email,
            $customer->phone,
            ''
        );

        $paymentInfo = new PaymentInfo(
            success_hook: 'customer_extra_credit_payment_success',
            failure_hook: 'customer_extra_credit_payment_fail',
            currency_code: $currencyCode,
            payment_method: $request['payment_method'],
            payment_platform: $request['payment_platform'],
            payer_id: $customer->id,
            receiver_id: '100',
            additional_data: $additionalData,
            payment_amount: $paymentAmount,
            external_redirect_link: $request['payment_platform'] == 'web' ? route('customer.purchase-package.index') : null,
            attribute: 'customer_extra_credit',
            attribute_id: $activeSubscription->id
        );

        $receiverInfo = new Receiver('receiver_name', 'example.png');
        $redirectLink = $this->generate_link($payer, $paymentInfo, $receiverInfo);

        if (!$redirectLink) {
            Toastr::error(translate('payment_method_is_not_available'));
            return back();
        }

        return redirect($redirectLink);
    }

    private function submitOfflinePaymentReview(Request $request, CustomerActivationInvoice $invoice, string $proofDirectory): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'method_id' => 'required|integer',
            'payment_note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                Toastr::error(translate($error));
            }
            return back();
        }

        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        if (!($offlinePaymentStatus['status'] ?? 0)) {
            Toastr::error(translate('offline_payment_is_not_available'));
            return back();
        }

        $method = OfflinePaymentMethod::query()
            ->where('id', $request->get('method_id'))
            ->where('status', 1)
            ->first();

        if (!$method) {
            Toastr::error(translate('offline_payment_method_not_found'));
            return back();
        }

        $offlinePaymentInfo = [
            'method_id' => $method->id,
            'method_name' => $method->method_name,
            'payment_note' => $request->get('payment_note', ''),
            'submitted_at' => now()->toDateTimeString(),
        ];

        $missingFields = [];
        foreach (($method->method_informations ?? []) as $field) {
            $inputName = $field['customer_input'] ?? null;
            if (!$inputName) {
                continue;
            }

            $fieldText = strtolower($inputName . ' ' . ($field['customer_placeholder'] ?? ''));
            $isImageField = str_contains($fieldText, 'screenshot')
                || str_contains($fieldText, 'image')
                || str_contains($fieldText, 'receipt')
                || str_contains($fieldText, 'proof');

            if ($isImageField) {
                if ($request->hasFile($inputName)) {
                    $offlinePaymentInfo[$inputName] = [
                        'image_name' => $this->upload(dir: $proofDirectory, format: 'webp', image: $request->file($inputName)),
                        'storage' => config('filesystems.disks.default') ?? 'public',
                    ];
                } elseif (($field['is_required'] ?? 0)) {
                    $missingFields[] = $inputName;
                }
                continue;
            }

            $value = $request->get($inputName);
            if (($field['is_required'] ?? 0) && ($value === null || $value === '')) {
                $missingFields[] = $inputName;
                continue;
            }

            if ($value !== null && $value !== '') {
                $offlinePaymentInfo[$inputName] = $value;
            }
        }

        if ($missingFields) {
            Toastr::error(translate('required_offline_payment_information_is_missing'));
            return back();
        }

        $metadata = $invoice->metadata ?: [];
        $metadata['offline_payment'] = $offlinePaymentInfo;

        $invoice->update([
            'payment_method' => 'offline_payment',
            'payment_reference' => 'offline-review:' . $method->id,
            'status' => 'pending_offline_review',
            'metadata' => $metadata,
        ]);

        Toastr::success(translate('offline_payment_information_submitted_successfully'));
        return redirect()->route('customer.purchase-package.index');
    }

    private function generateCustomerPackageInvoiceNo(): string
    {
        do {
            $invoiceNo = 'PKG-' . now()->format('YmdHis') . '-' . random_int(1000, 9999);
        } while (CustomerActivationInvoice::where('invoice_no', $invoiceNo)->exists());

        return $invoiceNo;
    }
}
