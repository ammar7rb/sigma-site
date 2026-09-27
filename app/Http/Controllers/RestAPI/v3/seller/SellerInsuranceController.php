<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\SellerInsurance;
use App\Services\SellerInsuranceService;
use App\Services\SellerLedgerService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use App\Utils\Helpers;
use DomainException;
use Illuminate\Http\JsonResponse;
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
        private readonly SellerLedgerService $ledger,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $summary = $this->sellerInsuranceService->getSummary($request->seller);
        $paymentGateways = collect(payment_gateways());
        $digitalPaymentStatus = getWebConfig(name: 'digital_payment');
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $operatingBalance = (float) ($this->ledger->summary($request->seller->id)[SellerLedgerService::OPERATING] ?? 0);

        return response()->json([
            'insurance' => $this->formatSummary($summary),
            'operating_balance' => $operatingBalance,
            'operating_balance_payment_available' => false,
            'digital_payment_available' => $paymentGateways->isNotEmpty() && ($digitalPaymentStatus['status'] ?? 0),
            'payment_gateways' => $paymentGateways->map(function ($gateway) {
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
            'payment_method' => 'required|string|max:100',
            'payment_platform' => 'required|string|max:50',
            'current_currency_code' => 'nullable|string|max:10',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        if ($request->get('payment_method') === 'operating_balance') {
            return response()->json(['message' => translate('seller_operating_balance_cannot_pay_insurance')], 422);
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
            $insurance = $this->sellerInsuranceService->getOrCreatePayableInsurance($seller);
            $currencyCode = $this->getCurrencyCode($request);
            $paymentAmount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: (float) $insurance->amount)
                : (float) $insurance->amount;
            $additionalData = [
                'business_name' => getWebConfig(name: 'company_name'),
                'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
                'payment_mode' => 'app',
                'payment_request_from' => 'vendor_app',
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
                payment_platform: $request->get('payment_platform'),
                payer_id: $seller->id,
                receiver_id: '100',
                additional_data: $additionalData,
                payment_amount: $paymentAmount,
                external_redirect_link: null,
                attribute: 'seller_insurance',
                attribute_id: $insurance->id,
            );
            $payer = new Payer(trim($seller->f_name.' '.$seller->l_name), $seller->email, $seller->phone, '');
            $redirectLink = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (! $redirectLink) {
                return response()->json(['message' => translate('payment_method_is_not_available')], 403);
            }

            if ($paymentRequestId = $this->extractPaymentRequestId((string) $redirectLink)) {
                $this->sellerInsuranceService->attachPaymentRequest($insurance, $paymentRequestId);
            }

            return response()->json([
                'redirect_link' => $redirectLink,
                'insurance' => $this->formatInsurance($insurance->fresh()),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 403);
        }
    }

    public function submitOfflinePayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'method_id' => 'required|integer',
            'method_informations' => 'required|string',
            'payment_note' => 'nullable|string|max:1000',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $offlinePaymentStatus = getWebConfig(name: 'offline_payment');
        $method = OfflinePaymentMethod::query()
            ->where('id', $request->get('method_id'))
            ->where('status', 1)
            ->first();
        if (! ($offlinePaymentStatus['status'] ?? 0) || ! $method) {
            return response()->json(['message' => translate('offline_payment_method_not_found')], 404);
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
            $insurance = $this->sellerInsuranceService->getOrCreatePayableInsurance($request->seller);
            $proofName = $this->upload(
                dir: 'seller-insurance/payment-proof/',
                format: 'webp',
                image: $request->file('payment_proof')
            );
            $insurance = $this->sellerInsuranceService->submitOfflinePayment($insurance, [
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
                'message' => translate('offline_payment_submitted_and_waiting_for_admin_review'),
                'insurance' => $this->formatInsurance($insurance),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 403);
        }
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

    private function formatSummary(array $summary): array
    {
        return [
            'enabled' => $summary['settings']['enabled'],
            'configured_amount' => $summary['settings']['amount'],
            'repayment_after_forfeiture' => $summary['settings']['repayment_after_forfeiture'],
            'required' => $summary['required'],
            'active' => $summary['active'],
            'pending_review' => $summary['pending_review'],
            'can_pay' => $summary['can_pay'],
            'latest' => $summary['latest_insurance'] ? $this->formatInsurance($summary['latest_insurance']) : null,
        ];
    }

    private function formatInsurance(SellerInsurance $insurance): array
    {
        return [
            'id' => $insurance->id,
            'transaction_id' => $insurance->transaction_id,
            'amount' => (float) $insurance->amount,
            'status' => $insurance->status,
            'payment_status' => $insurance->payment_status,
            'payment_method' => $insurance->payment_method,
            'payment_reference' => $insurance->payment_reference,
            'paid_at' => $insurance->paid_at,
            'reviewed_at' => $insurance->reviewed_at,
            'created_at' => $insurance->created_at,
        ];
    }
}
