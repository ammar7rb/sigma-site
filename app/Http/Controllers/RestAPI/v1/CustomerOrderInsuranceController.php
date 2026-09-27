<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Services\CustomerPostPurchaseInsuranceService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function App\Utils\payment_gateways;

class CustomerOrderInsuranceController extends Controller
{
    use FileManagerTrait;
    use Payment;
    use PaymentGatewayTrait;

    public function __construct(private readonly CustomerPostPurchaseInsuranceService $service) {}

    public function show(Request $request, int|string $id): JsonResponse
    {
        [$order, $insurance] = $this->resolve($request, $id);
        return response()->json([
            'claim' => $this->service->payload($order),
            'insurance_balance' => app(\App\Services\CustomerInsuranceBalanceService::class)->summary((int) $request->user()->id),
            'payment_options' => [
                'insurance_balance' => true,
                'digital_payment' => (bool) (getWebConfig(name: 'digital_payment')['status'] ?? 0),
                'offline_payment' => (bool) (getWebConfig(name: 'offline_payment')['status'] ?? 0),
                'gateways' => collect(payment_gateways())->map(fn ($item) => ['key' => $item['key_name'] ?? null, 'title' => translatePaymentText($item['key_name'] ?? '')])->filter(fn ($item) => $item['key'])->values(),
                'offline_methods' => OfflinePaymentMethod::query()->where('status', 1)->get(['id', 'method_name', 'payment_channel', 'method_fields']),
            ],
        ]);
    }

    public function pay(Request $request, int|string $id): JsonResponse
    {
        $request->validate(['payment_method' => 'required|string|max:100', 'current_currency_code' => 'nullable|string|max:10']);
        try {
            [$order, $insurance] = $this->resolve($request, $id);
            if ($request->input('payment_method') === 'customer_insurance_balance') {
                $paid = $this->service->payFromInsuranceBalance($insurance);
                return response()->json(['message' => translate('customer_order_insurance_paid_successfully'), 'claim' => $this->service->payload($order->fresh())]);
            }
            $gateway = collect(payment_gateways())->firstWhere('key_name', $request->input('payment_method'));
            if (! (getWebConfig(name: 'digital_payment')['status'] ?? 0) || ! $gateway) throw new DomainException('payment_method_is_not_available');
            $currency = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? $this->getPaymentGatewayCurrencyCode(key: $request->input('payment_method'), currentCurrency: $request->input('current_currency_code'))
                : (Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD');
            $amount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currency, amount: (float) $insurance->amount)
                : (float) $insurance->amount;
            $customer = $request->user();
            $payment = new PaymentInfo(
                success_hook: 'customer_order_insurance_payment_success', failure_hook: 'customer_order_insurance_payment_fail',
                currency_code: $currency, payment_method: $request->input('payment_method'), payment_platform: 'app',
                payer_id: $customer->id, receiver_id: '100', additional_data: ['order_insurance_id' => $insurance->id, 'order_id' => $order->id],
                payment_amount: $amount, external_redirect_link: 'customer-app://orders/' . $order->id . '/insurance',
                attribute: 'customer_order_insurance', attribute_id: $insurance->id,
            );
            $url = $this->generate_link(new Payer(trim($customer->f_name . ' ' . $customer->l_name), $customer->email, $customer->phone, ''), $payment, new Receiver('receiver_name', 'example.png'));
            if (! $url) throw new DomainException('payment_method_is_not_available');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (! empty($query['payment_id'])) $this->service->attachPaymentRequest($insurance, (string) $query['payment_id']);
            return response()->json(['redirect_link' => $url, 'payment_pending' => true]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    public function offline(Request $request, int|string $id): JsonResponse
    {
        $request->validate(['method_id' => 'required|integer', 'payment_note' => 'nullable|string|max:1000', 'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120']);
        try {
            [, $insurance] = $this->resolve($request, $id);
            $method = OfflinePaymentMethod::query()->whereKey($request->integer('method_id'))->where('status', 1)->first();
            if (! $method || ! (getWebConfig(name: 'offline_payment')['status'] ?? 0)) throw new DomainException('offline_payment_method_not_found');
            $proof = $this->upload('customer-order-insurance/payment-proof/', 'webp', $request->file('payment_proof'));
            $this->service->submitOfflinePayment($insurance, [
                'method_id' => $method->id, 'method_name' => $method->method_name, 'payment_note' => $request->input('payment_note'),
                'payment_proof' => ['image_name' => $proof, 'storage' => config('filesystems.disks.default') ?? 'public'], 'submitted_at' => now()->toIso8601String(),
            ]);
            return response()->json(['message' => translate('offline_payment_submitted_and_waiting_for_admin_review'), 'claim' => $this->service->payload($insurance->order)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    public function support(Request $request, int|string $id): JsonResponse
    {
        $request->validate(['message' => 'nullable|string|max:3000']);
        [, $insurance] = $this->resolve($request, $id);
        $ticket = $this->service->openSupportTicket($insurance, $request->input('message'));
        return response()->json(['message' => translate('support_ticket_created_successfully'), 'ticket_id' => $ticket->id]);
    }

    public function decline(Request $request, int|string $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:2000']);
        [$order, $insurance] = $this->resolve($request, $id);
        try {
            $this->service->decline($insurance, $request->input('reason'));
            return response()->json(['message' => translate('purchase_refund_scheduled_successfully'), 'claim' => $this->service->payload($order->fresh())]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    private function resolve(Request $request, int|string $id): array
    {
        $order = Order::query()->with('insurance')->whereKey($id)->where('customer_id', $request->user()->id)->where('is_guest', false)->firstOrFail();
        if (! $order->insurance) throw new DomainException('customer_order_insurance_not_found');
        return [$order, $order->insurance];
    }
}
