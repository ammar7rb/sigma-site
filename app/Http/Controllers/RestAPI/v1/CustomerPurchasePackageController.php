<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\CustomerActivationInvoice;
use App\Models\CustomerPurchaseLimitTransaction;
use App\Models\CustomerPurchasePackage;
use App\Services\SubscriptionDurationService;
use App\Models\OfflinePaymentMethod;
use App\Services\CustomerActivationInvoiceService;
use App\Services\CustomerPurchaseLimitService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use App\Utils\Convert;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function App\Utils\payment_gateways;

class CustomerPurchasePackageController extends Controller
{
    use Payment, PaymentGatewayTrait, FileManagerTrait;

    public function list(Request $request, CustomerPurchaseLimitService $purchaseLimitService): JsonResponse
    {
        $customer = $request->user();
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

        $paymentGatewayList = payment_gateways();
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');

        return response()->json([
            'packages' => $packages->map(function (CustomerPurchasePackage $package) {
                $duration = app(SubscriptionDurationService::class)->fromPackage($package);
                return array_merge($package->toArray(), [
                    'duration_unit' => $duration['unit'],
                    'duration_value' => $duration['value'],
                    'duration_label' => app(SubscriptionDurationService::class)->label($duration['unit'], $duration['value']),
                ]);
            })->values(),
            'limit_summary' => $purchaseLimitService->getLimitSummary($customer),
            'extra_credit_settings' => $purchaseLimitService->getExtraCreditSettings(),
            'digital_payment_available' => count($paymentGatewayList) > 0 && ($digitalPaymentStatus['status'] ?? 0),
            'payment_gateways' => $this->formatPaymentGateways($paymentGatewayList),
        ], 200);
    }

    public function limitSummary(Request $request, CustomerPurchaseLimitService $purchaseLimitService): JsonResponse
    {
        return response()->json([
            'limit_summary' => $purchaseLimitService->getLimitSummary($request->user()),
            'checkout_assessment' => $purchaseLimitService->getCheckoutLimitAssessment($request->user()),
        ], 200);
    }

    public function transactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required|integer|min:1',
            'offset' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $transactions = CustomerPurchaseLimitTransaction::with(['package'])
            ->where('customer_id', $request->user()->id)
            ->latest('id')
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        return response()->json([
            'limit' => (int) $request['limit'],
            'offset' => (int) $request['offset'],
            'total_size' => $transactions->total(),
            'transactions' => $transactions->items(),
        ], 200);
    }

    public function currentActivationInvoice(Request $request, CustomerActivationInvoiceService $activationInvoiceService): JsonResponse
    {
        $invoice = $activationInvoiceService->getPendingInvoiceForCustomer($request->user()->id);

        return response()->json([
            'invoice' => $invoice ? $this->formatActivationInvoice($invoice) : null,
            'payment_gateways' => $this->formatPaymentGateways(payment_gateways()),
            'digital_payment_available' => $this->digitalPaymentAvailable(),
        ], 200);
    }

    public function selectActivationInvoicePackage(Request $request, CustomerActivationInvoiceService $activationInvoiceService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'activation_invoice_id' => 'required|integer',
            'package_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $result = $activationInvoiceService->assignPackageToInvoice(
            (int) $request->user()->id,
            (int) $request['activation_invoice_id'],
            (int) $request['package_id']
        );

        if (($result['status'] ?? 0) !== 1) {
            return response()->json(['message' => translate($result['message'] ?? 'activation_invoice_not_available')], 403);
        }

        return response()->json([
            'message' => translate('activation_package_selected_successfully'),
            'invoice' => $this->formatActivationInvoice($result['invoice']),
        ], 200);
    }

    public function createActivationInvoiceForPackage(Request $request, CustomerActivationInvoiceService $activationInvoiceService): JsonResponse
    {
        $validator = Validator::make($request->all(), ['package_id' => 'required|integer']);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $result = $activationInvoiceService->createStandaloneInvoiceForPackage(
            $request->user(),
            (int) $request['package_id']
        );

        if (($result['status'] ?? 0) !== 1) {
            return response()->json(['message' => translate($result['message'] ?? 'customer_purchase_package_not_found')], 403);
        }

        return response()->json([
            'message' => translate('activation_invoice_created_successfully'),
            'invoice' => $this->formatActivationInvoice($result['invoice']),
        ], 200);
    }

    public function payActivationInvoice(Request $request, CustomerActivationInvoiceService $activationInvoiceService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'activation_invoice_id' => 'nullable|integer',
            'payment_method' => 'required|string',
            'payment_platform' => 'required|string',
            'current_currency_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        if (!$this->digitalPaymentAvailable()) {
            return response()->json(['message' => translate('payment_method_is_not_available')], 403);
        }

        $customer = $request->user();
        $invoice = $request['activation_invoice_id']
            ? CustomerActivationInvoice::where('id', $request['activation_invoice_id'])->where('customer_id', $customer->id)->first()
            : $activationInvoiceService->getPendingInvoiceForCustomer($customer->id);

        if (!$invoice || $invoice->payment_status !== 'unpaid' || !in_array($invoice->status, ['pending', 'pending_package_assignment'])) {
            return response()->json(['message' => translate('activation_invoice_not_found')], 404);
        }

        if ($invoice->status === 'pending_package_assignment' || !$invoice->customer_purchase_package_id) {
            return response()->json([
                'message' => translate('activation_invoice_needs_a_valid_package_before_payment'),
                'invoice' => $this->formatActivationInvoice($invoice),
            ], 403);
        }

        if ((float) $invoice->total_amount <= 0) {
            return response()->json(['message' => translate('invalid_activation_invoice_amount')], 403);
        }

        $currencyCode = $this->getCurrencyCode($request);
        $paymentAmount = $this->getGatewayPaymentAmount($request, (float) $invoice->total_amount, $currencyCode);
        $additionalData = [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
            'payment_mode' => 'app',
            'payment_request_from' => 'app',
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

        return $this->generatePaymentResponse(
            request: $request,
            customer: $customer,
            successHook: 'customer_activation_invoice_payment_success',
            failureHook: 'customer_activation_invoice_payment_fail',
            currencyCode: $currencyCode,
            paymentAmount: $paymentAmount,
            additionalData: $additionalData,
            attribute: 'customer_activation_invoice',
            attributeId: $invoice->id
        );
    }

    public function payActivationInvoiceByOfflinePayment(Request $request, CustomerActivationInvoiceService $activationInvoiceService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'activation_invoice_id' => 'nullable|integer',
            'method_id' => 'required|integer',
            'method_informations' => 'required|string',
            'payment_note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        if (!($offlinePaymentStatus['status'] ?? 0)) {
            return response()->json(['message' => translate('offline_payment_is_not_available')], 403);
        }

        $method = OfflinePaymentMethod::where(['id' => $request['method_id'], 'status' => 1])->first();
        if (!$method) {
            return response()->json(['message' => translate('offline_payment_method_not_found')], 404);
        }

        $customer = $request->user();
        $invoice = $request['activation_invoice_id']
            ? CustomerActivationInvoice::where('id', $request['activation_invoice_id'])->where('customer_id', $customer->id)->first()
            : $activationInvoiceService->getPendingInvoiceForCustomer($customer->id);

        if (!$invoice || $invoice->payment_status !== 'unpaid' || !in_array($invoice->status, ['pending', 'pending_package_assignment', 'pending_offline_review'])) {
            return response()->json(['message' => translate('activation_invoice_not_found')], 404);
        }

        if ($invoice->status === 'pending_package_assignment' || !$invoice->customer_purchase_package_id) {
            return response()->json([
                'message' => translate('activation_invoice_needs_a_valid_package_before_payment'),
                'invoice' => $this->formatActivationInvoice($invoice),
            ], 403);
        }

        if ((float) $invoice->total_amount <= 0) {
            return response()->json(['message' => translate('invalid_activation_invoice_amount')], 403);
        }

        $submittedInformation = $this->decodeOfflinePaymentInformation($request['method_informations']);
        if ($submittedInformation === null) {
            return response()->json(['message' => translate('invalid_offline_payment_information')], 403);
        }

        $offlinePaymentInfo = [
            'method_id' => $method->id,
            'method_name' => $method->method_name,
            'payment_note' => $request['payment_note'] ?? '',
            'submitted_at' => now()->toDateTimeString(),
        ];

        $missingFields = [];
        foreach (($method->method_informations ?? []) as $field) {
            $inputName = $field['customer_input'] ?? null;
            if (!$inputName) {
                continue;
            }

            if ($inputName === 'payment_screenshot') {
                if (($field['is_required'] ?? 0) && !$request->hasFile('payment_proof') && !$request->hasFile('payment_screenshot')) {
                    $missingFields[] = $inputName;
                }
                continue;
            }

            $value = $submittedInformation[$inputName] ?? null;
            if (($field['is_required'] ?? 0) && ($value === null || $value === '')) {
                $missingFields[] = $inputName;
                continue;
            }

            if ($value !== null) {
                $offlinePaymentInfo[$inputName] = $value;
            }
        }

        if ($missingFields) {
            return response()->json([
                'message' => translate('required_offline_payment_information_is_missing'),
                'missing_fields' => $missingFields,
            ], 403);
        }

        $proofFile = $request->file('payment_proof') ?: $request->file('payment_screenshot');
        if ($proofFile) {
            $offlinePaymentInfo['payment_proof'] = [
                'image_name' => $this->upload(dir: 'offline-payment/activation-invoice-proof/', format: 'webp', image: $proofFile),
                'storage' => config('filesystems.disks.default') ?? 'public',
            ];
        }

        $metadata = $invoice->metadata ?: [];
        $metadata['offline_payment'] = $offlinePaymentInfo;

        $invoice->update([
            'payment_method' => 'offline_payment',
            'payment_reference' => 'offline-review:' . $method->id,
            'status' => 'pending_offline_review',
            'metadata' => $metadata,
        ]);

        return response()->json([
            'message' => translate('offline_payment_information_submitted_successfully'),
            'invoice' => $this->formatActivationInvoice($invoice->fresh()),
        ], 200);
    }

    public function purchase(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|integer',
            'payment_method' => 'required|string',
            'payment_platform' => 'required|string',
            'current_currency_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        if (!$this->digitalPaymentAvailable()) {
            return response()->json(['message' => translate('payment_method_is_not_available')], 403);
        }

        $customer = $request->user();
        $package = CustomerPurchasePackage::query()
            ->where('id', $request['package_id'])
            ->where('status', 1)
            ->where(function ($query) use ($customer) {
                $query->where('is_custom', 0)
                    ->orWhere('customer_id', $customer->id);
            })
            ->first();

        if (!$package) {
            return response()->json(['message' => translate('customer_purchase_package_not_found')], 404);
        }

        if ((float) $package->package_price <= 0 || (float) $package->purchase_limit <= 0) {
            return response()->json(['message' => translate('invalid_customer_purchase_package')], 403);
        }

        $currencyCode = $this->getCurrencyCode($request);
        $paymentAmount = $this->getGatewayPaymentAmount($request, (float) $package->package_price, $currencyCode);

        $additionalData = [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
            'payment_mode' => 'app',
            'payment_request_from' => 'app',
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'package_name' => $package->name,
            'package_price' => (float) $package->package_price,
            'purchase_limit' => (float) $package->purchase_limit,
            'is_custom' => (bool) $package->is_custom,
        ];

        return $this->generatePaymentResponse(
            request: $request,
            customer: $customer,
            successHook: 'customer_purchase_package_payment_success',
            failureHook: 'customer_purchase_package_payment_fail',
            currencyCode: $currencyCode,
            paymentAmount: $paymentAmount,
            additionalData: $additionalData,
            attribute: 'customer_purchase_package',
            attributeId: $package->id
        );
    }

    public function purchaseExtraCredit(Request $request, CustomerPurchaseLimitService $purchaseLimitService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'credit_amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'payment_platform' => 'required|string',
            'current_currency_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        if (!$this->digitalPaymentAvailable()) {
            return response()->json(['message' => translate('payment_method_is_not_available')], 403);
        }

        $customer = $request->user();
        $activeSubscription = $purchaseLimitService->getActiveSubscription($customer);
        if (!$activeSubscription) {
            return response()->json(['message' => translate('customer_does_not_have_an_active_purchase_package')], 403);
        }

        $currentCurrency = $this->getCurrentCurrencyCode($request);
        $requestedCreditAmount = Convert::usdPaymentModule($request['credit_amount'], $currentCurrency);
        $extraCredit = $purchaseLimitService->calculateExtraCreditPurchase($requestedCreditAmount);
        if (!$extraCredit['status']) {
            return response()->json([
                'message' => translate($extraCredit['reason'] ?? 'invalid_extra_credit_amount'),
                'reason' => $extraCredit['reason'],
            ], 403);
        }

        $currencyCode = $this->getCurrencyCode($request);
        $paymentAmount = $this->getGatewayPaymentAmount($request, (float) $extraCredit['payment_amount'], $currencyCode);
        $additionalData = [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
            'payment_mode' => 'app',
            'payment_request_from' => 'app',
            'customer_id' => $customer->id,
            'subscription_id' => $activeSubscription->id,
            'package_id' => $activeSubscription->customer_purchase_package_id,
            'package_name' => $activeSubscription->package_name,
            'credit_amount' => (float) $extraCredit['credit_amount'],
            'paid_amount' => (float) $extraCredit['payment_amount'],
            'rate' => (float) ($extraCredit['settings']['rate'] ?? 0),
            'requested_credit_amount' => (float) $requestedCreditAmount,
        ];

        return $this->generatePaymentResponse(
            request: $request,
            customer: $customer,
            successHook: 'customer_extra_credit_payment_success',
            failureHook: 'customer_extra_credit_payment_fail',
            currencyCode: $currencyCode,
            paymentAmount: $paymentAmount,
            additionalData: $additionalData,
            attribute: 'customer_extra_credit',
            attributeId: $activeSubscription->id
        );
    }

    private function generatePaymentResponse(Request $request, mixed $customer, string $successHook, string $failureHook, string $currencyCode, float|int|string $paymentAmount, array $additionalData, string $attribute, int|string $attributeId): JsonResponse
    {
        $payer = new Payer(
            trim($customer->f_name . ' ' . $customer->l_name) ?: $customer->name,
            $customer->email,
            $customer->phone,
            ''
        );

        $paymentInfo = new PaymentInfo(
            success_hook: $successHook,
            failure_hook: $failureHook,
            currency_code: $currencyCode,
            payment_method: $request['payment_method'],
            payment_platform: $request['payment_platform'],
            payer_id: $customer->id,
            receiver_id: '100',
            additional_data: $additionalData,
            payment_amount: $paymentAmount,
            external_redirect_link: null,
            attribute: $attribute,
            attribute_id: $attributeId
        );

        $redirectLink = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));

        if (!$redirectLink) {
            return response()->json(['message' => translate('payment_method_is_not_available')], 403);
        }

        return response()->json(['redirect_link' => $redirectLink], 200);
    }

    private function getCurrencyCode(Request $request): string
    {
        if (getWebConfig(name: 'currency_model') == 'multi_currency') {
            return $this->getPaymentGatewayCurrencyCode(
                key: $request['payment_method'],
                currentCurrency: $this->getCurrentCurrencyCode($request)
            );
        }

        return Currency::find(getWebConfig(name: 'system_default_currency'))->code;
    }

    private function getGatewayPaymentAmount(Request $request, float $amount, string $currencyCode): float|string
    {
        if (getWebConfig(name: 'currency_model') == 'multi_currency') {
            return usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: $amount);
        }

        return $amount;
    }

    private function getCurrentCurrencyCode(Request $request): string
    {
        return $request['current_currency_code'] ?: Currency::find(getWebConfig(name: 'system_default_currency'))->code;
    }

    private function digitalPaymentAvailable(): bool
    {
        $paymentGatewayList = payment_gateways();
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');

        return count($paymentGatewayList) > 0 && ($digitalPaymentStatus['status'] ?? 0);
    }

    private function formatPaymentGateways(iterable $paymentGatewayList): array
    {
        return collect($paymentGatewayList)->map(function ($gateway) {
            $gatewayData = !empty($gateway->additional_data) ? json_decode($gateway->additional_data) : null;

            return [
                'key_name' => $gateway->key_name,
                'title' => $gatewayData?->gateway_title ?? ucwords(str_replace('_', ' ', $gateway->key_name)),
            ];
        })->values()->all();
    }

    private function decodeOfflinePaymentInformation(string $encodedInformation): ?array
    {
        $decoded = base64_decode($encodedInformation, true);
        $json = $decoded !== false ? $decoded : $encodedInformation;
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private function formatActivationInvoice(CustomerActivationInvoice $invoice): array
    {
        $offlinePaymentInfo = $invoice->metadata['offline_payment'] ?? null;

        return [
            'id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'order_id' => $invoice->order_id,
            'order_group_id' => $invoice->order_group_id,
            'package' => [
                'id' => $invoice->customer_purchase_package_id,
                'name' => $invoice->package_name,
                'price' => (float) $invoice->package_price,
                'purchase_limit' => (float) $invoice->package_purchase_limit,
                'duration_unit' => data_get($invoice->metadata, 'package_duration.unit', 'lifetime'),
                'duration_value' => data_get($invoice->metadata, 'package_duration.value'),
                'duration_label' => data_get($invoice->metadata, 'package_duration.unit') === 'lifetime'
                    ? 'lifetime'
                    : sprintf('%d_%s', (int) data_get($invoice->metadata, 'package_duration.value'), data_get($invoice->metadata, 'package_duration.unit')),
            ],
            'insurance' => [
                'original_amount' => (float) $invoice->insurance_original_amount,
                'discount_amount' => (float) $invoice->insurance_discount_amount,
                'discount_type' => $invoice->insurance_discount_type,
                'amount' => (float) $invoice->insurance_amount,
                'period_start' => $invoice->insurance_period_start,
                'period_end' => $invoice->insurance_period_end,
            ],
            'total_amount' => (float) $invoice->total_amount,
            'paid_amount' => (float) $invoice->paid_amount,
            'currency_code' => $invoice->currency_code,
            'payment_status' => $invoice->payment_status,
            'status' => $invoice->status,
            'can_pay' => $invoice->payment_status === 'unpaid'
                && $invoice->status === 'pending'
                && $invoice->customer_purchase_package_id
                && (float) $invoice->total_amount > 0,
            'offline_payment' => [
                'submitted' => (bool) $offlinePaymentInfo,
                'pending_review' => $invoice->status === 'pending_offline_review',
                'info' => $offlinePaymentInfo,
            ],
            'message' => app(CustomerActivationInvoiceService::class)->getActivationHoldMessage($invoice),
            'created_at' => $invoice->created_at,
        ];
    }
}
