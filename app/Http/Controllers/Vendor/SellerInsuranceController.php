<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Services\SellerInsuranceService;
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

class SellerInsuranceController extends Controller
{
    use FileManagerTrait;
    use Payment;
    use PaymentGatewayTrait;

    public function __construct(
        private readonly SellerInsuranceService $sellerInsuranceService,
    ) {}

    public function index(): View
    {
        $seller = auth('seller')->user();
        $paymentGatewayList = payment_gateways();
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');

        return view('vendor-views.insurance.index', [
            'summary' => $this->sellerInsuranceService->getSummary($seller),
            'insurances' => $seller->insurances()->latest('id')->limit(10)->get(),
            'paymentGatewayList' => $paymentGatewayList,
            'digitalPaymentAvailable' => count($paymentGatewayList) > 0 && ($digitalPaymentStatus['status'] ?? 0),
            'offlinePaymentAvailable' => (bool) ($offlinePaymentStatus['status'] ?? 0),
            'offlinePaymentMethods' => OfflinePaymentMethod::query()->where('status', 1)->get(),
        ]);
    }

    public function pay(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
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

        try {
            $seller = auth('seller')->user();
            $insurance = $this->sellerInsuranceService->getOrCreatePayableInsurance($seller);
            $currencyCode = $this->getCurrencyCode($request);
            $paymentAmount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: (float) $insurance->amount)
                : (float) $insurance->amount;

            $additionalData = [
                'business_name' => getWebConfig(name: 'company_name'),
                'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
                'payment_mode' => 'web',
                'payment_request_from' => 'vendor_web',
                'seller_id' => $seller->id,
                'seller_insurance_id' => $insurance->id,
                'insurance_transaction_id' => $insurance->transaction_id,
                'insurance_amount' => (float) $insurance->amount,
            ];

            $paymentInfo = new PaymentInfo(
                success_hook: 'seller_insurance_payment_success',
                failure_hook: 'seller_insurance_payment_fail',
                currency_code: $currencyCode,
                payment_method: $request->get('payment_method'),
                payment_platform: 'web',
                payer_id: $seller->id,
                receiver_id: '100',
                additional_data: $additionalData,
                payment_amount: $paymentAmount,
                external_redirect_link: route('vendor.insurance.index'),
                attribute: 'seller_insurance',
                attribute_id: $insurance->id,
            );

            $payer = new Payer(trim($seller->f_name.' '.$seller->l_name), $seller->email, $seller->phone, '');
            $redirectLink = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (! $redirectLink) {
                ToastMagic::error(translate('payment_method_is_not_available'));

                return back();
            }

            if ($paymentRequestId = $this->extractPaymentRequestId((string) $redirectLink)) {
                $this->sellerInsuranceService->attachPaymentRequest($insurance, $paymentRequestId);
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
        $method = OfflinePaymentMethod::query()
            ->where('id', $request->get('method_id'))
            ->where('status', 1)
            ->first();
        if (! ($offlinePaymentStatus['status'] ?? 0) || ! $method) {
            ToastMagic::error(translate('offline_payment_method_not_found'));

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
            $seller = auth('seller')->user();
            $insurance = $this->sellerInsuranceService->getOrCreatePayableInsurance($seller);
            $proofName = $this->upload(
                dir: 'seller-insurance/payment-proof/',
                format: 'webp',
                image: $request->file('payment_proof')
            );

            $offlinePayment = [
                'method_id' => $method->id,
                'method_name' => $method->method_name,
                'method_information' => $submittedInformation,
                'payment_note' => $request->get('payment_note'),
                'payment_proof' => [
                    'image_name' => $proofName,
                    'storage' => config('filesystems.disks.default') ?? 'public',
                ],
                'submitted_at' => now()->toDateTimeString(),
            ];
            $this->sellerInsuranceService->submitOfflinePayment($insurance, $offlinePayment);

            ToastMagic::success(translate('offline_payment_submitted_and_waiting_for_admin_review'));

            return redirect()->route('vendor.insurance.index');
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));

            return back();
        }
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
