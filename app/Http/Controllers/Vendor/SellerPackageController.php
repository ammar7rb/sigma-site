<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\SellerPackage;
use App\Models\SellerPackageSubscription;
use App\Services\SellerLedgerService;
use App\Services\SellerPackagePurchaseService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
    ) {}

    public function index(): View
    {
        $seller = auth('seller')->user();
        $paymentGatewayList = payment_gateways();
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');

        $ledgerSummary = app(SellerLedgerService::class)->summary((int) $seller->id);

        return view('vendor-views.package.index', [
            'packages' => SellerPackage::query()->active()->orderBy('sort_order')->orderBy('id')->get(),
            'summary' => $this->purchaseService->getSummary($seller),
            'subscriptions' => $seller->packageSubscriptions()->latest('id')->limit(10)->get(),
            'performance' => null,
            'operatingBalance' => (float) ($ledgerSummary[SellerLedgerService::OPERATING] ?? 0),
            'paymentGatewayList' => $paymentGatewayList,
            'digitalPaymentAvailable' => count($paymentGatewayList) > 0 && ($digitalPaymentStatus['status'] ?? 0),
            'offlinePaymentAvailable' => (bool) ($offlinePaymentStatus['status'] ?? 0),
            'offlinePaymentMethods' => OfflinePaymentMethod::query()->where('status', 1)->get(),
        ]);
    }

    /**
     * The package overview already includes the active subscription metrics.
     * Keep this named endpoint so historic links cannot fail while the seller
     * remains inside the same single source of truth for package data.
     */
    public function performance(int $subscriptionId): RedirectResponse
    {
        $subscription = SellerPackageSubscription::query()
            ->where('seller_id', auth('seller')->id())
            ->findOrFail($subscriptionId);

        return redirect()->route('vendor.packages.index', ['subscription' => $subscription->id]);
    }

    public function cancel(Request $request, int $subscriptionId): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $subscription = SellerPackageSubscription::query()
            ->where('seller_id', auth('seller')->id())
            ->findOrFail($subscriptionId);

        try {
            $this->purchaseService->cancel(
                subscription: $subscription,
                reason: (string) $request->input('reason'),
                requestedByType: 'seller',
                requestedById: (int) auth('seller')->id(),
            );
            ToastMagic::success(translate('package_cancellation_request_submitted'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return redirect()->route('vendor.packages.index');
    }

    public function pay(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|integer',
            'payment_method' => 'required|string|max:100',
            'current_currency_code' => 'nullable|string|max:10',
        ]);
        if ($validator->fails()) {
            ToastMagic::error($validator->errors()->first());

            return back();
        }

        $gateways = collect(payment_gateways());
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');
        if (! ($digitalPaymentStatus['status'] ?? 0) || ! $gateways->firstWhere('key_name', $request->get('payment_method'))) {
            ToastMagic::error(translate('payment_method_is_not_available'));

            return back();
        }

        $package = SellerPackage::query()->active()->find($request->get('package_id'));
        if (! $package) {
            ToastMagic::error(translate('seller_package_not_found_or_inactive'));

            return back();
        }

        try {
            $seller = auth('seller')->user();
            $subscription = $this->purchaseService->getOrCreatePendingSubscription($seller, $package);
            $currencyCode = $this->getCurrencyCode($request);
            $paymentAmount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: (float) $subscription->paid_package_price)
                : (float) $subscription->paid_package_price;
            $paymentInfo = new PaymentInfo(
                success_hook: 'seller_package_payment_success',
                failure_hook: 'seller_package_payment_fail',
                currency_code: $currencyCode,
                payment_method: $request->get('payment_method'),
                payment_platform: 'web',
                payer_id: $seller->id,
                receiver_id: '100',
                additional_data: $this->paymentAdditionalData($seller, $subscription, 'vendor_web'),
                payment_amount: $paymentAmount,
                external_redirect_link: route('vendor.packages.index'),
                attribute: 'seller_package_subscription',
                attribute_id: $subscription->id,
            );
            $payer = new Payer(trim($seller->f_name.' '.$seller->l_name), $seller->email, $seller->phone, '');
            $redirectLink = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (! $redirectLink) {
                ToastMagic::error(translate('payment_method_is_not_available'));

                return back();
            }

            if ($paymentRequestId = $this->extractPaymentRequestId((string) $redirectLink)) {
                $this->purchaseService->attachPaymentRequest($subscription, $paymentRequestId);
            }

            return redirect($redirectLink);
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));

            return back();
        }
    }

    public function submitOfflinePayment(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|integer',
            'method_id' => 'required|integer',
            'method_information' => 'nullable|array',
            'method_information.*' => 'nullable|string|max:1000',
            'payment_note' => 'nullable|string|max:1000',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        if ($validator->fails()) {
            ToastMagic::error($validator->errors()->first());

            return back();
        }

        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $method = OfflinePaymentMethod::query()->whereKey($request->get('method_id'))->where('status', 1)->first();
        $package = SellerPackage::query()->active()->find($request->get('package_id'));
        if (! ($offlinePaymentStatus['status'] ?? 0) || ! $method) {
            ToastMagic::error(translate('offline_payment_method_not_found'));

            return back();
        }
        if (! $package) {
            ToastMagic::error(translate('seller_package_not_found_or_inactive'));

            return back();
        }

        $submittedInformation = (array) $request->get('method_information', []);
        foreach (($method->method_informations ?? []) as $field) {
            $inputName = $field['customer_input'] ?? null;
            if (! $inputName || $inputName === 'payment_screenshot') {
                continue;
            }
            if (($field['is_required'] ?? 0) && empty($submittedInformation[$inputName])) {
                ToastMagic::error(translate('required_offline_payment_information_is_missing'));

                return back();
            }
        }

        try {
            $subscription = $this->purchaseService->getOrCreatePendingSubscription(auth('seller')->user(), $package);
            $proofName = $this->upload(
                dir: 'seller-package/payment-proof/',
                format: 'webp',
                image: $request->file('payment_proof')
            );
            $this->purchaseService->submitOfflinePayment($subscription, [
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

            ToastMagic::success(translate('package_offline_payment_submitted_and_waiting_for_admin_review'));

            return redirect()->route('vendor.packages.index');
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));

            return back();
        }
    }

    private function paymentAdditionalData(mixed $seller, mixed $subscription, string $source): array
    {
        return [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
            'payment_mode' => $source === 'vendor_web' ? 'web' : 'app',
            'payment_request_from' => $source,
            'seller_id' => $seller->id,
            'seller_package_subscription_id' => $subscription->id,
            'seller_package_id' => $subscription->seller_package_id,
            'package_name' => $subscription->package_name,
            'package_price' => (float) $subscription->paid_package_price,
        ];
    }

    private function getCurrencyCode(Request $request): string
    {
        if (getWebConfig(name: 'currency_model') === 'multi_currency') {
            return $this->getPaymentGatewayCurrencyCode(
                key: $request->get('payment_method'),
                currentCurrency: $request->get('current_currency_code') ?: session('currency_code')
            );
        }

        return Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD';
    }

    private function extractPaymentRequestId(string $redirectLink): ?string
    {
        parse_str((string) parse_url($redirectLink, PHP_URL_QUERY), $query);

        return isset($query['payment_id']) && is_string($query['payment_id']) ? $query['payment_id'] : null;
    }
}
