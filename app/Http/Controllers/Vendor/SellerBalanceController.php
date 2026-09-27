<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Services\SellerBalanceDepositService;
use App\Services\SellerBalanceFundingSettingsService;
use App\Services\SellerFinancePresentationService;
use App\Services\SellerLedgerService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use App\Utils\Convert;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function App\Utils\payment_gateways;

/** Web entry point for the same seller-balance services used by the API. */
class SellerBalanceController extends Controller
{
    use FileManagerTrait, Payment, PaymentGatewayTrait;

    public function __construct(
        private readonly SellerBalanceDepositService $deposits,
        private readonly SellerLedgerService $ledger,
        private readonly SellerBalanceFundingSettingsService $fundingSettings,
        private readonly SellerFinancePresentationService $financePresentation,
    ) {
    }

    public function index(Request $request)
    {
        $seller = auth('seller')->user();
        $funding = $this->fundingSettings->get();
        $gateways = collect(payment_gateways())
            ->filter(fn ($gateway) => $funding['digital_gateways'] === [] || in_array($gateway->key_name, $funding['digital_gateways'], true))
            ->values();
        $summary = $this->ledger->summary((int) $seller->id);

        return view('vendor-views.balance.index', [
            'summary' => [
                'sales_total' => (float) ($summary[SellerLedgerService::SALES_TOTAL] ?? 0),
                'pending' => (float) ($summary[SellerLedgerService::PENDING] ?? 0),
                'available' => (float) ($summary[SellerLedgerService::AVAILABLE] ?? 0),
                'operating' => (float) ($summary[SellerLedgerService::OPERATING] ?? 0),
                'pending_withdraw' => (float) ($summary[SellerLedgerService::PENDING_WITHDRAW] ?? 0),
                'withdrawn' => (float) ($summary[SellerLedgerService::WITHDRAWN] ?? 0),
            ],
            'financeOverview' => $this->financePresentation->overview((int) $seller->id, 50),
            'deposits' => $seller->balanceDeposits()->latest('id')->paginate(10),
            'fundingSettings' => $funding,
            'paymentGatewayList' => $gateways,
            'digitalPaymentAvailable' => $funding['digital_enabled'] && $gateways->isNotEmpty() && (bool) (getWebConfig(name: 'digital_payment')['status'] ?? 0),
            'offlinePaymentAvailable' => $funding['offline_enabled'] && (bool) (getWebConfig(name: 'offline_payment')['status'] ?? 0),
            'offlinePaymentMethods' => OfflinePaymentMethod::query()->where('status', 1)->get(),
        ]);
    }

    public function pay(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), ['amount' => 'required|numeric|min:0.01', 'payment_method' => 'required|string|max:100']);
        if ($validator->fails()) return back()->withErrors($validator)->withInput();

        $funding = $this->fundingSettings->get();
        $gateways = collect(payment_gateways());
        if (!$funding['digital_enabled'] || !(getWebConfig(name: 'digital_payment')['status'] ?? 0)
            || !$this->fundingSettings->isGatewayAllowed((string) $request->payment_method)
            || !$gateways->firstWhere('key_name', $request->payment_method)) {
            return back()->withErrors(['payment_method' => translate('payment_method_is_not_available')]);
        }
        try {
            $seller = auth('seller')->user();
            $amount = (float) Convert::usd($request->amount);
            $this->fundingSettings->assertAmountAllowed($amount);
            $currency = Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: getCurrencyCode(type: 'default');
            $deposit = $this->deposits->createDigital($seller, $amount, $currency, (string) $request->payment_method);
            $payment = new PaymentInfo(success_hook: 'seller_balance_deposit_payment_success', failure_hook: 'seller_balance_deposit_payment_fail', currency_code: $currency, payment_method: (string) $request->payment_method, payment_platform: 'web', payer_id: $seller->id, receiver_id: '100', additional_data: ['payment_mode' => 'web', 'payment_request_from' => 'vendor_web', 'seller_id' => $seller->id, 'seller_balance_deposit_id' => $deposit->id, 'deposit_amount' => $amount], payment_amount: $amount, external_redirect_link: null, attribute: 'seller_balance_deposit', attribute_id: $deposit->id);
            $link = $this->generate_link(new Payer(trim($seller->f_name . ' ' . $seller->l_name), $seller->email, $seller->phone, ''), $payment, new Receiver('receiver_name', 'example.png'));
            if (!$link) return back()->withErrors(['payment_method' => translate('payment_method_is_not_available')]);
            if ($requestId = $this->extractPaymentRequestId((string) $link)) $this->deposits->attachPaymentRequest($deposit, $requestId);
            return redirect()->away($link);
        } catch (DomainException $exception) {
            return back()->withErrors(['amount' => translate($exception->getMessage())])->withInput();
        }
    }

    public function submitOfflinePayment(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), ['amount' => 'required|numeric|min:0.01', 'method_id' => 'required|integer', 'method_information' => 'nullable|array', 'payment_note' => 'nullable|string|max:1000', 'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120']);
        if ($validator->fails()) return back()->withErrors($validator)->withInput();

        $method = OfflinePaymentMethod::query()->whereKey($request->method_id)->where('status', 1)->first();
        $funding = $this->fundingSettings->get();
        if (!$funding['offline_enabled'] || !(getWebConfig(name: 'offline_payment')['status'] ?? 0) || !$method) return back()->withErrors(['method_id' => translate('offline_payment_method_not_found')]);
        $information = (array) $request->input('method_information', []);
        foreach ((array) ($method->method_informations ?? []) as $field) {
            $name = $field['customer_input'] ?? null;
            if ($name && $name !== 'payment_screenshot' && ($field['is_required'] ?? 0) && blank($information[$name] ?? null)) return back()->withErrors(['method_information' => translate('required_offline_payment_information_is_missing')])->withInput();
        }
        try {
            $amount = (float) Convert::usd($request->amount);
            $this->fundingSettings->assertAmountAllowed($amount);
            $proof = $this->upload(dir: 'seller-balance/payment-proof/', format: 'webp', image: $request->file('payment_proof'));
            $this->deposits->createOffline(auth('seller')->user(), ['amount' => $amount, 'currency_code' => getCurrencyCode(type: 'default'), 'payment_method' => 'offline_payment', 'offline_payment_method_id' => $method->id, 'offline_information' => $information, 'payment_proof' => ['image_name' => $proof, 'storage' => config('filesystems.disks.default') ?? 'public'], 'payment_note' => $request->payment_note]);
            return back()->with('success', translate('seller_balance_deposit_submitted'));
        } catch (DomainException $exception) {
            return back()->withErrors(['amount' => translate($exception->getMessage())])->withInput();
        }
    }

    private function extractPaymentRequestId(string $link): ?string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        return isset($query['payment_id']) && is_string($query['payment_id']) ? $query['payment_id'] : null;
    }
}
