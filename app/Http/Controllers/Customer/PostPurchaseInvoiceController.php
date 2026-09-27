<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\PostPurchaseInvoice;
use App\Services\PostPurchaseInvoiceService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use Brian2694\Toastr\Facades\Toastr;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function App\Utils\payment_gateways;

class PostPurchaseInvoiceController extends Controller
{
    use FileManagerTrait;
    use Payment;
    use PaymentGatewayTrait;

    public function __construct(private readonly PostPurchaseInvoiceService $invoices)
    {
    }

    /** The customer-facing suspended-orders queue.  It keeps phase-two
     * payment visible after checkout instead of leaving it behind a redirect. */
    public function index(): View
    {
        $invoices = PostPurchaseInvoice::query()
            ->with('order:id,order_group_id,post_purchase_status')
            ->where('customer_id', auth('customer')->id())
            ->whereIn('status', [
                PostPurchaseInvoice::STATUS_PENDING,
                PostPurchaseInvoice::STATUS_AWAITING_REVIEW,
                PostPurchaseInvoice::STATUS_EXPIRED,
            ])
            ->latest('id')
            ->paginate(20);

        $view = theme_root_path() === 'theme_aster'
            ? 'theme-views.post-purchase-invoice-list'
            : 'web-views.post-purchase-invoice-list';

        return view($view, compact('invoices'));
    }

    public function show(int $invoiceId): View|RedirectResponse
    {
        $invoice = $this->customerInvoice($invoiceId);
        if (! $invoice) {
            Toastr::warning(translate('invalid_order'));
            return redirect()->route('account-oder');
        }

        $view = theme_root_path() === 'theme_aster'
            ? 'theme-views.post-purchase-invoice'
            : 'web-views.post-purchase-invoice';

        return view($view, [
            'invoice' => $invoice,
            'summary' => $this->invoices->paymentSummary($invoice),
            'digitalPaymentAvailable' => (bool) (getWebConfig(name: 'digital_payment')['status'] ?? 0) && count(payment_gateways()) > 0,
            'paymentGatewayList' => payment_gateways(),
            'offlinePaymentMethods' => OfflinePaymentMethod::query()->where('status', 1)->get(),
            'offlinePaymentAvailable' => (bool) (getWebConfig(name: 'offline_payment')['status'] ?? 0),
        ]);
    }

    public function payDigitally(Request $request, int $invoiceId): RedirectResponse
    {
        $invoice = $this->customerInvoice($invoiceId);
        if (! $invoice) {
            abort(404);
        }

        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|string|max:100',
            'current_currency_code' => 'nullable|string|max:10',
        ]);
        if ($validator->fails()) {
            Toastr::error($validator->errors()->first());
            return back();
        }

        try {
            $summary = $this->invoices->paymentSummary($invoice);
            $amount = (float) $summary['external_amount_due'];
            if ($amount <= 0) {
                Toastr::info(translate('no_payment_is_required'));
                return back();
            }

            $gateways = collect(payment_gateways());
            if (! (getWebConfig(name: 'digital_payment')['status'] ?? 0)
                || ! $gateways->firstWhere('key_name', $request->get('payment_method'))) {
                Toastr::error(translate('payment_method_is_not_available'));
                return back();
            }

            $currencyCode = $this->currencyCode($request);
            $paymentAmount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: $amount)
                : $amount;
            $customer = auth('customer')->user();

            $paymentInfo = new PaymentInfo(
                success_hook: 'post_purchase_invoice_payment_success',
                failure_hook: 'post_purchase_invoice_payment_fail',
                currency_code: $currencyCode,
                payment_method: $request->get('payment_method'),
                payment_platform: 'web',
                payer_id: $customer->id,
                receiver_id: '100',
                additional_data: [
                    'payment_mode' => 'web',
                    'payment_request_from' => 'customer_post_purchase_invoice',
                    'customer_id' => $customer->id,
                    'post_purchase_invoice_id' => $invoice->id,
                    'external_amount_due' => $amount,
                ],
                payment_amount: $paymentAmount,
                external_redirect_link: route('post-purchase-invoices.show', $invoice->id),
                attribute: 'post_purchase_invoice',
                attribute_id: $invoice->id,
            );

            $payer = new Payer(trim($customer->f_name.' '.$customer->l_name), $customer->email, $customer->phone, '');
            $redirect = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (! $redirect) {
                Toastr::error(translate('payment_method_is_not_available'));
                return back();
            }

            return redirect($redirect);
        } catch (DomainException $exception) {
            Toastr::error(translate($exception->getMessage()));
            return back();
        }
    }

    public function useInsuranceBalance(int $invoiceId): RedirectResponse
    {
        $invoice = $this->customerInvoice($invoiceId);
        if (! $invoice) {
            abort(404);
        }

        try {
            $this->invoices->applyCustomerInsuranceBalance($invoice, auth('customer')->user());
            Toastr::success(translate('insurance_balance_applied_to_insurance_only'));
        } catch (DomainException $exception) {
            Toastr::warning(translate($exception->getMessage()));
        }

        return back();
    }

    public function submitOffline(Request $request, int $invoiceId): RedirectResponse
    {
        $invoice = $this->customerInvoice($invoiceId);
        if (! $invoice) {
            abort(404);
        }

        $validator = Validator::make($request->all(), [
            'method_id' => 'required|integer',
            'payment_note' => 'nullable|string|max:1000',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        if ($validator->fails()) {
            Toastr::error($validator->errors()->first());
            return back();
        }

        $method = OfflinePaymentMethod::query()->whereKey($request->method_id)->where('status', 1)->first();
        if (! $method || ! (getWebConfig(name: 'offline_payment')['status'] ?? 0)) {
            Toastr::error(translate('offline_payment_method_not_found'));
            return back();
        }

        try {
            $invoice->refresh();
            if (! $invoice->isPayable()) {
                throw new DomainException('post_purchase_invoice_is_not_payable');
            }
            $metadata = $invoice->metadata ?: [];
            $metadata['offline_payment'] = [
                'method_id' => $method->id,
                'method_name' => $method->method_name,
                'payment_note' => $request->payment_note,
                'payment_proof' => [
                    'image_name' => $this->upload('offline-payment/post-purchase-invoice/', 'webp', $request->file('payment_proof')),
                    'storage' => config('filesystems.disks.default') ?? 'public',
                ],
                'external_amount_due' => $this->invoices->paymentSummary($invoice)['external_amount_due'],
                'submitted_at' => now()->toDateTimeString(),
            ];
            $invoice->update(['status' => PostPurchaseInvoice::STATUS_AWAITING_REVIEW, 'metadata' => $metadata]);
            Toastr::success(translate('offline_payment_submitted_and_waiting_for_admin_review'));
        } catch (DomainException $exception) {
            Toastr::warning(translate($exception->getMessage()));
        }

        return back();
    }

    private function customerInvoice(int $invoiceId): ?PostPurchaseInvoice
    {
        return PostPurchaseInvoice::query()
            ->with('order')
            ->whereKey($invoiceId)
            ->where('customer_id', auth('customer')->id())
            ->first();
    }

    private function currencyCode(Request $request): string
    {
        if (getWebConfig(name: 'currency_model') === 'multi_currency') {
            return $this->getPaymentGatewayCurrencyCode(
                key: $request->get('payment_method'),
                currentCurrency: $request->get('current_currency_code') ?: session('currency_code')
            );
        }

        return Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD';
    }
}
