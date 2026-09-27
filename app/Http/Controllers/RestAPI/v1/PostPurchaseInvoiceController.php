<?php

namespace App\Http\Controllers\RestAPI\v1;

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
use DomainException;
use Illuminate\Http\JsonResponse;
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

    public function index(Request $request): JsonResponse
    {
        $customer = $request->user('api');
        $invoices = PostPurchaseInvoice::query()
            ->with('order:id,order_group_id,order_amount,post_purchase_status')
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->paginate((int) min(50, max(1, $request->integer('limit', 20))));

        return response()->json([
            'invoices' => collect($invoices->items())->map(fn (PostPurchaseInvoice $invoice) => $this->payload($invoice))->values(),
            'pagination' => [
                'total' => $invoices->total(),
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = $this->invoiceForCustomer($request, $invoiceId);
        if (! $invoice) {
            return response()->json(['message' => translate('invalid_order')], 404);
        }

        return response()->json(['invoice' => $this->payload($invoice)]);
    }

    public function useInsuranceBalance(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = $this->invoiceForCustomer($request, $invoiceId);
        if (! $invoice) {
            return response()->json(['message' => translate('invalid_order')], 404);
        }

        try {
            $invoice = $this->invoices->applyCustomerInsuranceBalance($invoice, $request->user('api'));
            return response()->json([
                'message' => translate('insurance_balance_applied_to_insurance_only'),
                'invoice' => $this->payload($invoice),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    /* Backward-compatible mobile aliases use an order id, while the new
       contract itself remains invoice-based. */
    public function mobileClaim(Request $request, int $orderId): JsonResponse
    {
        $invoice = $this->invoiceForCustomerOrder($request, $orderId);
        if (! $invoice) {
            return response()->json(['message' => translate('invalid_order')], 404);
        }

        return response()->json($this->mobileEnvelope($invoice));
    }

    public function mobilePay(Request $request, int $orderId): JsonResponse
    {
        $invoice = $this->invoiceForCustomerOrder($request, $orderId);
        if (! $invoice) {
            return response()->json(['message' => translate('invalid_order')], 404);
        }

        $validator = Validator::make($request->all(), ['payment_method' => 'required|string|max:100']);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            if ($request->payment_method === 'customer_insurance_balance') {
                $invoice = $this->invoices->applyCustomerInsuranceBalance($invoice, $request->user('api'));
                return response()->json(array_merge(['message' => translate('insurance_balance_applied_to_insurance_only')], $this->mobileEnvelope($invoice)));
            }

            $summary = $this->invoices->paymentSummary($invoice);
            $amount = (float) $summary['external_amount_due'];
            $gateway = collect(payment_gateways())->firstWhere('key_name', $request->payment_method);
            if ($amount <= 0 || ! $gateway || ! (getWebConfig(name: 'digital_payment')['status'] ?? 0)) {
                throw new DomainException('payment_method_is_not_available');
            }
            $currency = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? $this->getPaymentGatewayCurrencyCode($request->payment_method, $request->current_currency_code)
                : (Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD');
            $paymentAmount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currency, amount: $amount)
                : $amount;
            $customer = $request->user('api');
            $redirect = $this->generate_link(new Payer(trim($customer->f_name.' '.$customer->l_name), $customer->email, $customer->phone, ''), new PaymentInfo(
                success_hook: 'post_purchase_invoice_payment_success',
                failure_hook: 'post_purchase_invoice_payment_fail',
                currency_code: $currency,
                payment_method: $request->payment_method,
                payment_platform: 'app',
                payer_id: $customer->id,
                receiver_id: '100',
                additional_data: [
                    'payment_mode' => 'app', 'payment_request_from' => 'customer_post_purchase_invoice',
                    'customer_id' => $customer->id, 'post_purchase_invoice_id' => $invoice->id,
                    'external_amount_due' => $amount,
                ],
                payment_amount: $paymentAmount,
                external_redirect_link: null,
                attribute: 'post_purchase_invoice',
                attribute_id: $invoice->id,
            ), new Receiver('receiver_name', 'example.png'));

            return response()->json(array_merge(['redirect_link' => $redirect], $this->mobileEnvelope($invoice)));
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    public function mobileOffline(Request $request, int $orderId): JsonResponse
    {
        $invoice = $this->invoiceForCustomerOrder($request, $orderId);
        if (! $invoice) {
            return response()->json(['message' => translate('invalid_order')], 404);
        }
        $validator = Validator::make($request->all(), [
            'method_id' => 'required|integer',
            'sender_name' => 'required|string|min:5|max:150',
            'sender_identifier' => 'required|string|min:3|max:150',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'payment_note' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        $method = OfflinePaymentMethod::query()->whereKey($request->method_id)->where('status', 1)->first();
        if (! $method || ! (getWebConfig(name: 'offline_payment')['status'] ?? 0)) {
            return response()->json(['message' => translate('offline_payment_method_not_found')], 422);
        }
        if (! $invoice->isPayable()) {
            return response()->json(['message' => translate('post_purchase_invoice_is_not_payable')], 422);
        }
        $metadata = $invoice->metadata ?: [];
        $metadata['offline_payment'] = [
            'method_id' => $method->id,
            'method_name' => $method->method_name,
            'sender_name' => $request->string('sender_name')->trim()->toString(),
            'sender_identifier' => $request->string('sender_identifier')->trim()->toString(),
            'payment_note' => $request->payment_note,
            'payment_proof' => ['image_name' => $this->upload('offline-payment/post-purchase-invoice/', 'webp', $request->file('payment_proof')), 'storage' => config('filesystems.disks.default') ?? 'public'],
            'external_amount_due' => $this->invoices->paymentSummary($invoice)['external_amount_due'], 'submitted_at' => now()->toDateTimeString(),
        ];
        $invoice->update(['status' => PostPurchaseInvoice::STATUS_AWAITING_REVIEW, 'metadata' => $metadata]);

        return response()->json(array_merge(['message' => translate('offline_payment_submitted_and_waiting_for_admin_review')], $this->mobileEnvelope($invoice->fresh())));
    }

    public function mobileSupport(Request $request, int $orderId): JsonResponse
    {
        $invoice = $this->invoiceForCustomerOrder($request, $orderId);
        if (! $invoice) {
            return response()->json(['message' => translate('invalid_order')], 404);
        }

        // The application opens the existing support-ticket screen after this
        // acknowledgment. We retain the invoice reference in its response so
        // that a ticket is never connected to a seller conversation.
        return response()->json(['message' => translate('contact_admin_support'), 'invoice_id' => $invoice->id, 'support_available' => true]);
    }

    /**
     * Keeps the existing mobile "decline" action safe during the migration:
     * it does not refund money or cancel an order by itself.  It records the
     * customer's payment problem for the administrator and then the app
     * opens the support ticket screen.
     */
    public function mobileDecline(Request $request, int $orderId): JsonResponse
    {
        $invoice = $this->invoiceForCustomerOrder($request, $orderId);
        if (! $invoice) {
            return response()->json(['message' => translate('invalid_order')], 404);
        }

        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:1000']);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        if (! $invoice->isPayable()) {
            return response()->json(['message' => translate('post_purchase_invoice_is_not_payable')], 422);
        }

        $metadata = $invoice->metadata ?: [];
        $metadata['customer_support_request'] = [
            'reason' => $request->string('reason')->trim()->toString(),
            'requested_at' => now()->toIso8601String(),
            'requested_by' => $request->user('api')->id,
            'type' => 'post_purchase_payment_problem',
        ];
        $metadata['purchase_refund'] = [
            'status' => 'requested_for_admin_review',
            'requested_at' => now()->toIso8601String(),
        ];
        $invoice->update([
            'status' => PostPurchaseInvoice::STATUS_AWAITING_REVIEW,
            'metadata' => $metadata,
        ]);

        return response()->json(array_merge([
            'message' => translate('contact_admin_support'),
        ], $this->mobileEnvelope($invoice->fresh())));
    }

    private function invoiceForCustomer(Request $request, int $invoiceId): ?PostPurchaseInvoice
    {
        return PostPurchaseInvoice::query()
            ->with('order:id,order_group_id,order_amount,post_purchase_status')
            ->whereKey($invoiceId)
            ->where('customer_id', $request->user('api')->id)
            ->first();
    }

    private function invoiceForCustomerOrder(Request $request, int $orderId): ?PostPurchaseInvoice
    {
        return PostPurchaseInvoice::query()->with('order')
            ->where('order_id', $orderId)
            ->where('customer_id', $request->user('api')->id)
            ->first();
    }

    private function payload(PostPurchaseInvoice $invoice): array
    {
        return array_merge($this->invoices->paymentSummary($invoice), [
            'contract_version' => $invoice->contract_version,
            'order_group_id' => $invoice->order?->order_group_id,
            'first_payment_amount' => (float) ($invoice->metadata['first_payment_amount'] ?? $invoice->order?->order_amount ?? 0),
            'support_required_if_unable_to_pay' => true,
            'web_payment_url' => route('post-purchase-invoices.show', $invoice->id),
        ]);
    }

    private function mobileEnvelope(PostPurchaseInvoice $invoice): array
    {
        $summary = $this->invoices->paymentSummary($invoice);
        $claimStatus = match ($invoice->status) {
            PostPurchaseInvoice::STATUS_AWAITING_REVIEW => 'pending_review',
            PostPurchaseInvoice::STATUS_PAID => 'paid',
            PostPurchaseInvoice::STATUS_EXPIRED => 'expired',
            default => 'pending_payment',
        };
        $customer = $invoice->customer;
        $balance = $this->invoices->customerInsuranceBalanceSummary($invoice->customer_id);

        return [
            'claim' => [
                'contract_version' => $invoice->contract_version,
                'flow_status' => $invoice->order?->post_purchase_status,
                'order_reference' => '#'.$invoice->order_id,
                'purchase_amount' => (float) ($invoice->metadata['first_payment_amount'] ?? $invoice->order?->order_amount ?? 0),
                'tax' => ['amount' => (float) $invoice->tax_amount],
                'insurance' => ['amount' => (float) $invoice->insurance_amount, 'payment_status' => $invoice->status === PostPurchaseInvoice::STATUS_PAID ? 'paid' : 'unpaid', 'status' => $claimStatus, 'payment_due_at' => $invoice->payment_due_at?->toIso8601String(), 'balance_use_policy' => 'insurance_only'],
                'order_is_suspended_until_insurance_payment' => $invoice->status !== PostPurchaseInvoice::STATUS_PAID,
                'support_available' => true,
                'purchase_refund' => ($invoice->metadata['purchase_refund'] ?? ['status' => 'not_requested', 'due_at' => null]),
                'external_amount_due' => $summary['external_amount_due'],
            ],
            'insurance_balance' => $balance,
            'payment_options' => [
                // Keep the choice visible even when the balance is zero so the
                // app can explain the shortfall and offer a deposit action.
                'insurance_balance' => (float) $invoice->insurance_amount > (float) $summary['insurance_balance_paid'],
                'digital_payment' => (bool) (getWebConfig(name: 'digital_payment')['status'] ?? 0),
                'offline_payment' => (bool) (getWebConfig(name: 'offline_payment')['status'] ?? 0),
                'gateways' => collect(payment_gateways())->map(fn ($gateway) => ['key' => $gateway['key_name'], 'title' => $gateway['key_name']])->values(),
                'offline_methods' => OfflinePaymentMethod::query()->where('status', 1)->get(['id', 'method_name', 'payment_channel', 'method_fields'])->map(fn ($method) => ['id' => (string) $method->id, 'method_name' => $method->method_name, 'payment_channel' => $method->payment_channel, 'method_fields' => $method->method_fields])->values(),
            ],
        ];
    }
}
