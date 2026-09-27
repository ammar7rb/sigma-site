<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\SellerPackage;
use App\Models\SellerPackageSubscription;
use App\Services\SellerPackagePurchaseService;
use App\Services\SellerPackagePerformanceService;
use App\Services\SellerLedgerService;
use App\Services\SubscriptionDurationService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use App\Utils\Helpers;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function App\Utils\payment_gateways;

class SellerPackageController extends Controller
{
    use FileManagerTrait;
    use Payment;
    use PaymentGatewayTrait;

    public function __construct(
        private readonly SellerPackagePurchaseService $purchaseService,
        private readonly SellerLedgerService $ledger,
        private readonly SubscriptionDurationService $durationService,
        private readonly SellerPackagePerformanceService $performanceService,
    ) {}

    public function performance(Request $request, int|string $subscription): JsonResponse
    {
        $subscription = SellerPackageSubscription::query()->where('seller_id', $request->seller->id)->find($subscription);
        if (! $subscription) {
            return response()->json(['message' => translate('seller_package_subscription_not_found')], 404);
        }

        return response()->json(['performance' => $this->performanceService->report($subscription)]);
    }

    public function cancel(Request $request, int|string $subscription): JsonResponse
    {
        $validator = Validator::make($request->all(), ['reason' => 'required|string|min:3|max:1000']);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 422);
        }
        $subscription = SellerPackageSubscription::query()->where('seller_id', $request->seller->id)->find($subscription);
        if (! $subscription) {
            return response()->json(['message' => translate('seller_package_subscription_not_found')], 404);
        }
        try {
            $subscription = $this->purchaseService->cancel($subscription, $request->string('reason')->toString(), 'seller', $request->seller->id);
            return response()->json([
                'message' => translate('seller_package_cancellation_recorded_without_refund'),
                'subscription' => $this->formatSubscription($subscription),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $gateways = collect(payment_gateways());
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');

        $summary = $this->purchaseService->getSummary($request->seller);
        $operatingBalance = (float) ($this->ledger->summary($request->seller->id)[SellerLedgerService::OPERATING] ?? 0);

        return response()->json([
            'packages' => SellerPackage::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                ->map(function (SellerPackage $package) {
                    $duration = $this->durationService->fromPackage($package);
                    return array_merge($package->toArray(), [
                        'product_limit' => null,
                        'product_duration_days' => null,
                        'product_publishing_unlimited' => true,
                        'package_purpose' => 'advertising',
                        'duration_unit' => $duration['unit'],
                        'duration_value' => $duration['value'],
                        'duration_label' => $this->durationService->label($duration['unit'], $duration['value']),
                    ]);
                })->values(),
            'subscription' => $this->formatSummary($summary),
            'operating_balance' => $operatingBalance,
            'operating_balance_payment_available' => $summary['can_purchase'] && $operatingBalance > 0,
            'digital_payment_available' => $gateways->isNotEmpty() && ($digitalPaymentStatus['status'] ?? 0),
            'payment_gateways' => $gateways->map(function ($gateway) {
                $gatewayData = ! empty($gateway->additional_data) ? json_decode($gateway->additional_data) : null;

                return [
                    'key_name' => $gateway->key_name,
                    'title' => $gatewayData?->gateway_title ?? ucwords(str_replace('_', ' ', $gateway->key_name)),
                ];
            })->values(),
            'offline_payment_available' => (bool) ($offlinePaymentStatus['status'] ?? 0),
            'offline_payment_methods' => OfflinePaymentMethod::query()
                ->where('status', 1)
                ->get(['id', 'method_name', 'payment_channel', 'method_fields', 'method_informations']),
        ]);
    }

    public function pay(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|integer',
            'payment_method' => 'required|string|max:100',
            'payment_platform' => 'required|string|max:50',
            'current_currency_code' => 'nullable|string|max:10',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $package = SellerPackage::query()->active()->find($request->get('package_id'));
        if (! $package) {
            return response()->json(['message' => translate('seller_package_not_found_or_inactive')], 404);
        }

        if ($request->get('payment_method') !== 'operating_balance') {
            $gateways = collect(payment_gateways());
            $digitalPaymentStatus = getWebConfig(name: 'digital_payment');
            if (! ($digitalPaymentStatus['status'] ?? 0) || ! $gateways->firstWhere('key_name', $request->get('payment_method'))) {
                return response()->json(['message' => translate('payment_method_is_not_available')], 403);
            }
        }

        try {
            $seller = $request->seller;
            $subscription = $this->purchaseService->getOrCreatePendingSubscription($seller, $package);
            if ($request->get('payment_method') === 'operating_balance') {
                $result = $this->purchaseService->payFromOperatingBalance($subscription);

                return response()->json([
                    'message' => translate('seller_package_payment_completed_from_operating_balance'),
                    'subscription' => $this->formatSubscription($result['subscription']),
                ]);
            }

            $currencyCode = $this->getCurrencyCode($request);
            $paymentAmount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: (float) $subscription->paid_package_price)
                : (float) $subscription->paid_package_price;
            $paymentInfo = new PaymentInfo(
                success_hook: 'seller_package_payment_success',
                failure_hook: 'seller_package_payment_fail',
                currency_code: $currencyCode,
                payment_method: $request->get('payment_method'),
                payment_platform: $request->get('payment_platform'),
                payer_id: $seller->id,
                receiver_id: '100',
                additional_data: [
                    'business_name' => getWebConfig(name: 'company_name'),
                    'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
                    'payment_mode' => 'app',
                    'payment_request_from' => 'vendor_app',
                    'seller_id' => $seller->id,
                    'seller_package_subscription_id' => $subscription->id,
                    'seller_package_id' => $subscription->seller_package_id,
                    'package_name' => $subscription->package_name,
                    'package_price' => (float) $subscription->paid_package_price,
                ],
                payment_amount: $paymentAmount,
                external_redirect_link: null,
                attribute: 'seller_package_subscription',
                attribute_id: $subscription->id,
            );
            $payer = new Payer(trim($seller->f_name.' '.$seller->l_name), $seller->email, $seller->phone, '');
            $redirectLink = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (! $redirectLink) {
                return response()->json(['message' => translate('payment_method_is_not_available')], 403);
            }
            if ($paymentRequestId = $this->extractPaymentRequestId((string) $redirectLink)) {
                $this->purchaseService->attachPaymentRequest($subscription, $paymentRequestId);
            }

            return response()->json([
                'redirect_link' => $redirectLink,
                'subscription' => $this->formatSubscription($subscription->fresh()),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 403);
        }
    }

    public function submitOfflinePayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|integer',
            'method_id' => 'required|integer',
            'method_informations' => 'required|string',
            'payment_note' => 'nullable|string|max:1000',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $method = OfflinePaymentMethod::query()->whereKey($request->get('method_id'))->where('status', 1)->first();
        $package = SellerPackage::query()->active()->find($request->get('package_id'));
        if (! ($offlinePaymentStatus['status'] ?? 0) || ! $method) {
            return response()->json(['message' => translate('offline_payment_method_not_found')], 404);
        }
        if (! $package) {
            return response()->json(['message' => translate('seller_package_not_found_or_inactive')], 404);
        }

        $submittedInformation = $this->decodeOfflineInformation($request->get('method_informations'));
        if ($submittedInformation === null) {
            return response()->json(['message' => translate('invalid_offline_payment_information')], 403);
        }
        $missingFields = [];
        foreach (($method->method_informations ?? []) as $field) {
            $inputName = $field['customer_input'] ?? null;
            if (! $inputName || $inputName === 'payment_screenshot') {
                continue;
            }
            if (($field['is_required'] ?? 0) && empty($submittedInformation[$inputName])) {
                $missingFields[] = $inputName;
            }
        }
        if ($missingFields) {
            return response()->json([
                'message' => translate('required_offline_payment_information_is_missing'),
                'missing_fields' => $missingFields,
            ], 403);
        }

        try {
            $subscription = $this->purchaseService->getOrCreatePendingSubscription($request->seller, $package);
            $proofName = $this->upload(
                dir: 'seller-package/payment-proof/',
                format: 'webp',
                image: $request->file('payment_proof')
            );
            $subscription = $this->purchaseService->submitOfflinePayment($subscription, [
                'method_id' => $method->id,
                'method_name' => $method->method_name,
                'method_information' => $submittedInformation,
                'payment_note' => $request->get('payment_note'),
                'payment_proof' => [
                    'image_name' => $proofName,
                    'storage' => config('filesystems.disks.default') ?? 'public',
                ],
                'submitted_at' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'message' => translate('package_offline_payment_submitted_and_waiting_for_admin_review'),
                'subscription' => $this->formatSubscription($subscription),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 403);
        }
    }

    private function formatSummary(array $summary): array
    {
        return [
            'insurance_satisfied' => $summary['insurance_satisfied'],
            'can_purchase' => $summary['can_purchase'],
            'pending_review' => $summary['pending_review'],
            'operating_balance_payment_available' => $summary['can_purchase'],
            'active' => $summary['active_subscription'] ? $this->formatSubscription($summary['active_subscription']) : null,
            'pending' => $summary['pending_subscription'] ? $this->formatSubscription($summary['pending_subscription']) : null,
        ];
    }

    private function formatSubscription(SellerPackageSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'package_id' => $subscription->seller_package_id,
            'package_name' => $subscription->package_name,
            'paid_package_price' => (float) $subscription->paid_package_price,
            'product_limit' => null,
            'product_adjustment_limit' => $subscription->product_adjustment_limit,
            'used_product_limit' => 0,
            'remaining_product_limit' => null,
            'product_duration_days' => null,
            'product_publishing_unlimited' => true,
            'package_purpose' => 'advertising',
            'search_promotion_limit' => $subscription->search_promotion_limit,
            'search_promotion_adjustment_limit' => $subscription->search_promotion_adjustment_limit,
            'used_search_promotion_limit' => $subscription->used_search_promotion_limit,
            'remaining_search_promotion_limit' => max(0, $subscription->search_promotion_limit
                + $subscription->search_promotion_adjustment_limit
                - $subscription->used_search_promotion_limit),
            'search_promotion_duration_days' => $subscription->search_promotion_duration_days,
            'homepage_promotion_limit' => $subscription->homepage_promotion_limit,
            'homepage_promotion_adjustment_limit' => $subscription->homepage_promotion_adjustment_limit,
            'used_homepage_promotion_limit' => $subscription->used_homepage_promotion_limit,
            'remaining_homepage_promotion_limit' => max(0, $subscription->homepage_promotion_limit
                + $subscription->homepage_promotion_adjustment_limit
                - $subscription->used_homepage_promotion_limit),
            'homepage_promotion_duration_days' => $subscription->homepage_promotion_duration_days,
            'coupon_limit' => $subscription->coupon_limit,
            'coupon_adjustment_limit' => $subscription->coupon_adjustment_limit,
            'used_coupon_limit' => $subscription->used_coupon_limit,
            'remaining_coupon_limit' => max(0, $subscription->coupon_limit
                + $subscription->coupon_adjustment_limit
                - $subscription->used_coupon_limit),
            'package_validity_days' => $subscription->package_validity_days,
            'duration_unit' => $subscription->duration_unit ?: ($subscription->package_validity_days ? 'days' : 'lifetime'),
            'duration_value' => $subscription->duration_value ?: $subscription->package_validity_days,
            'started_at' => $subscription->started_at ?: $subscription->starts_at,
            'status' => $subscription->status,
            'payment_status' => $subscription->payment_status,
            'payment_method' => $subscription->payment_method,
            'starts_at' => $subscription->starts_at,
            'expires_at' => $subscription->expires_at,
            'remaining_days' => $subscription->expires_at ? max(0, now()->diffInDays($subscription->expires_at, false)) : null,
            'duration_status' => $subscription->duration_unit === 'lifetime'
                || (! $subscription->duration_unit && ! $subscription->package_validity_days)
                ? 'lifetime'
                : ($subscription->expires_at?->isPast() ? 'expired' : 'active'),
            'search_priority' => $subscription->search_priority,
            'cancellation_effect' => $subscription->cancellation_effect,
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'cancellation_requested_at' => $subscription->cancellation_requested_at,
            'cancellation_reason' => $subscription->cancellation_reason,
            'created_at' => $subscription->created_at,
        ];
    }

    private function getCurrencyCode(Request $request): string
    {
        if (getWebConfig(name: 'currency_model') === 'multi_currency') {
            return $this->getPaymentGatewayCurrencyCode(
                key: $request->get('payment_method'),
                currentCurrency: $request->get('current_currency_code')
            );
        }

        return Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD';
    }

    private function decodeOfflineInformation(string $information): ?array
    {
        $decoded = base64_decode($information, true);
        $json = $decoded !== false ? $decoded : $information;
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private function extractPaymentRequestId(string $redirectLink): ?string
    {
        parse_str((string) parse_url($redirectLink, PHP_URL_QUERY), $query);

        return isset($query['payment_id']) && is_string($query['payment_id']) ? $query['payment_id'] : null;
    }
}
