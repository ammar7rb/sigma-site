<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\SellerLedgerEntry;
use App\Services\SellerBalanceDepositService;
use App\Services\SellerBalanceFundingSettingsService;
use App\Services\SellerLedgerService;
use App\Services\SellerFinancePresentationService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use App\Utils\Helpers;
use App\Utils\Convert;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function App\Utils\payment_gateways;

class SellerBalanceController extends Controller
{
    use FileManagerTrait, Payment, PaymentGatewayTrait;

    public function __construct(private readonly SellerBalanceDepositService $depositService, private readonly SellerLedgerService $ledger, private readonly SellerBalanceFundingSettingsService $fundingSettings, private readonly SellerFinancePresentationService $financePresentation)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1', 'records_page' => 'nullable|integer|min:1', 'orders_page' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|min:1|max:100', 'records_limit' => 'nullable|integer|min:1|max:100', 'category' => 'nullable|in:balance,insurance,shipping']);
        $seller = $request->seller;
        $gateways = collect(payment_gateways());
        $digital = getWebConfig(name: 'digital_payment');
        $offline = getWebConfig(name: 'offline_payment');
        $funding = $this->fundingSettings->get();
        $gateways = $gateways->filter(fn ($gateway) => $funding['digital_gateways'] === [] || in_array($gateway->key_name, $funding['digital_gateways'], true))->values();
        $summary = $this->ledger->summary((int) $seller->id);
        $financeOverview = $this->financePresentation->overview((int) $seller->id, (int) $request->get('records_limit', 20), (int) $request->get('records_page', 1), $request->get('category'));
        $deposits = \App\Models\SellerBalanceDeposit::query()->where('seller_id', $seller->id)->latest('id')->paginate($request->get('limit', 10));
        $ledgerEntries = SellerLedgerEntry::query()
            ->where('seller_id', $seller->id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'bucket', 'direction', 'amount', 'event_type', 'reporting_category', 'reference_type', 'reference_id', 'reference_code', 'created_at']);

        return response()->json([
            'summary' => $summary,
            'insurance_wallet' => app(\App\Services\SellerDashboardOverviewService::class)->orderInsuranceWallet((int) $seller->id),
            'financial_summary' => [
                'order_insurance_credit' => (float) ($summary[SellerLedgerService::ORDER_INSURANCE_CREDIT] ?? 0),
                'sales_total' => (float) ($summary[SellerLedgerService::SALES_TOTAL] ?? 0),
                'available' => (float) ($summary[SellerLedgerService::AVAILABLE] ?? 0),
                'pending' => (float) ($summary[SellerLedgerService::PENDING] ?? 0),
                'operating' => (float) ($summary[SellerLedgerService::OPERATING] ?? 0),
                'pending_withdraw' => (float) ($summary[SellerLedgerService::PENDING_WITHDRAW] ?? 0),
                'withdrawn' => (float) ($summary[SellerLedgerService::WITHDRAWN] ?? 0),
                'shipping_due_total' => app(\App\Services\SellerDashboardOverviewService::class)->shippingDueTotal((int) $seller->id),
            ],
            'settlements' => [
                'available' => (float) ($summary[SellerLedgerService::AVAILABLE] ?? 0),
                'pending' => (float) ($summary[SellerLedgerService::PENDING] ?? 0),
                'next_collection_at' => $financeOverview['next_collection_at'],
            ],
            'financial_tabs' => $financeOverview['tabs'],
            'records_pagination' => $financeOverview['pagination'],
            'order_dues' => app(\App\Services\SellerOrderDuesPresentationService::class)->page((int) $seller->id, (int) $request->get('orders_page', 1)),
            'deposits' => $deposits,
            'financial_records' => $ledgerEntries,
            'digital_payment_available' => $funding['digital_enabled'] && $gateways->isNotEmpty() && ($digital['status'] ?? 0),
            'payment_gateways' => $gateways->map(fn ($gateway) => ['key_name' => $gateway->key_name, 'title' => $gateway->key_name])->values(),
            'offline_payment_available' => $funding['offline_enabled'] && (bool) ($offline['status'] ?? 0),
            'offline_payment_methods' => OfflinePaymentMethod::query()->where('status', 1)->get(['id', 'method_name', 'payment_channel', 'method_fields', 'method_informations']),
            'funding_settings' => ['min_amount' => Convert::default($funding['min_amount']), 'max_amount' => Convert::default($funding['max_amount']), 'currency_code' => Currency::find(getWebConfig(name: 'system_default_currency'))?->code, 'company_accounts' => $funding['company_accounts']],
        ]);
    }

    public function pay(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['amount' => 'required|numeric|min:0.01', 'payment_method' => 'required|string|max:100', 'payment_platform' => 'nullable|string|max:50', 'wallet_target' => 'nullable|in:operating,insurance']);
        if ($validator->fails()) return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 422);
        $gateways = collect(payment_gateways());
        $digital = getWebConfig(name: 'digital_payment');
        $funding = $this->fundingSettings->get();
        if (!$funding['digital_enabled'] || !($digital['status'] ?? 0) || !$this->fundingSettings->isGatewayAllowed((string) $request->get('payment_method')) || !$gateways->firstWhere('key_name', $request->get('payment_method'))) return response()->json(['message' => translate('payment_method_is_not_available')], 403);
        try {
            $seller = $request->seller;
            $amount = (float) Convert::usd($request->get('amount'));
            $this->fundingSettings->assertAmountAllowed($amount);
            $currencyCode = Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD';
            $walletTarget = (string) $request->get('wallet_target', 'operating');
            $deposit = $this->depositService->createDigital($seller, $amount, $currencyCode, $request->get('payment_method'), $walletTarget);
            $paymentInfo = new PaymentInfo(success_hook: 'seller_balance_deposit_payment_success', failure_hook: 'seller_balance_deposit_payment_fail', currency_code: $currencyCode, payment_method: $request->get('payment_method'), payment_platform: $request->get('payment_platform', 'app'), payer_id: $seller->id, receiver_id: '100', additional_data: ['payment_mode' => 'app', 'payment_request_from' => 'vendor_app', 'seller_id' => $seller->id, 'seller_balance_deposit_id' => $deposit->id, 'deposit_amount' => $amount, 'wallet_target' => $walletTarget], payment_amount: $amount, external_redirect_link: null, attribute: 'seller_balance_deposit', attribute_id: $deposit->id);
            $payer = new Payer(trim($seller->f_name.' '.$seller->l_name), $seller->email, $seller->phone, '');
            $redirectLink = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (!$redirectLink) return response()->json(['message' => translate('payment_method_is_not_available')], 403);
            if ($paymentRequestId = $this->extractPaymentRequestId((string) $redirectLink)) $this->depositService->attachPaymentRequest($deposit, $paymentRequestId);
            return response()->json(['redirect_link' => $redirectLink, 'deposit' => $deposit->fresh()]);
        } catch (DomainException $exception) { return response()->json(['message' => translate($exception->getMessage())], 403); }
    }

    public function submitOfflinePayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['amount' => 'required|numeric|min:0.01', 'method_id' => 'required|integer', 'method_informations' => 'nullable|string', 'payment_note' => 'nullable|string|max:1000', 'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120', 'wallet_target' => 'nullable|in:operating,insurance']);
        if ($validator->fails()) return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 422);
        $method = OfflinePaymentMethod::query()->whereKey($request->get('method_id'))->where('status', 1)->first();
        $funding = $this->fundingSettings->get();
        if (!$funding['offline_enabled'] || !(getWebConfig(name: 'offline_payment')['status'] ?? 0) || !$method) return response()->json(['message' => translate('offline_payment_method_not_found')], 404);
        $information = json_decode((string) $request->get('method_informations', '{}'), true);
        if (!is_array($information)) return response()->json(['message' => translate('invalid_offline_payment_information')], 422);
        $missingFields = [];
        foreach (($method->method_informations ?? []) as $field) {
            $name = $field['customer_input'] ?? null;
            if ($name && $name !== 'payment_screenshot' && ($field['is_required'] ?? 0) && empty($information[$name])) $missingFields[] = $name;
        }
        if ($missingFields) return response()->json(['message' => translate('required_offline_payment_information_is_missing'), 'missing_fields' => $missingFields], 422);
        try {
            $amount = (float) \App\Utils\Convert::usd($request->get('amount'));
            $this->fundingSettings->assertAmountAllowed($amount);
            $proofName = $this->upload(dir: 'seller-balance/payment-proof/', format: 'webp', image: $request->file('payment_proof'));
            $deposit = $this->depositService->createOffline($request->seller, ['amount' => $amount, 'currency_code' => getCurrencyCode(type: 'default'), 'payment_method' => 'offline_payment', 'offline_payment_method_id' => $method->id, 'offline_information' => $information, 'payment_proof' => ['image_name' => $proofName, 'storage' => config('filesystems.disks.default') ?? 'public'], 'payment_note' => $request->get('payment_note'), 'wallet_target' => $request->get('wallet_target', 'operating')]);
            return response()->json(['message' => translate('seller_balance_deposit_submitted'), 'deposit' => $deposit], 200);
        } catch (DomainException $exception) { return response()->json(['message' => translate($exception->getMessage())], 422); }
    }

    private function extractPaymentRequestId(string $redirectLink): ?string
    {
        parse_str((string) parse_url($redirectLink, PHP_URL_QUERY), $query);
        return isset($query['payment_id']) && is_string($query['payment_id']) ? $query['payment_id'] : null;
    }
}
