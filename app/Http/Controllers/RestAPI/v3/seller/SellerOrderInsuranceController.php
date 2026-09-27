<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Services\SellerOrderInsuranceService;
use App\Services\PostPurchaseInvoiceService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function App\Utils\payment_gateways;

class SellerOrderInsuranceController extends Controller
{
    use FileManagerTrait;
    use Payment;
    use PaymentGatewayTrait;

    public function __construct(private readonly SellerOrderInsuranceService $service) {}

    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            [$order, $insurance] = $this->resolve($request, $id);
            if (! $insurance) return response()->json(['enabled' => false, 'can_view_order_details' => true]);
            $payload = $this->service->payload($insurance);
            if (!$this->service->canViewDetails($insurance)) {
                unset($payload['order_id']);
                $payload['order_reference'] = '***' . str_pad(substr((string) $order->id, -3), 3, '0', STR_PAD_LEFT);
                $payload['restricted_access_token'] = app(\App\Services\OrderCommerceContractService::class)->restrictedAccessToken($order);
            }
            return response()->json([
                'enabled' => true,
                'seller_order_insurance' => $payload,
                'payment_options' => [
                    'operating_balance' => false, 'reusable_insurance_credit' => true,
                    'digital_payment' => (bool) (getWebConfig(name: 'digital_payment')['status'] ?? 0),
                    'offline_payment' => (bool) (getWebConfig(name: 'offline_payment')['status'] ?? 0),
                    'digital_gateways' => collect(payment_gateways())->map(fn ($gateway) => [
                        'key' => $gateway['key_name'] ?? null,
                        'title' => translatePaymentText($gateway['key_name'] ?? ''),
                    ])->filter(fn ($gateway) => $gateway['key'])->values(),
                    'offline_methods' => OfflinePaymentMethod::query()->where('status', 1)->get(['id', 'method_name', 'method_fields']),
                ],
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    public function pay(Request $request, int|string $id): JsonResponse
    {
        $request->validate(['payment_method' => 'required|string|max:100', 'current_currency_code' => 'nullable|string|max:10']);
        try {
            [$order, $insurance] = $this->resolve($request, $id);
            if (! $insurance) return response()->json(['message' => translate('seller_order_insurance_is_disabled')]);
            $method = (string) $request->input('payment_method');
            if ($method === 'operating_balance') {
                throw new DomainException('seller_operating_balance_cannot_pay_insurance');
            }
            if ($method === 'seller_order_insurance_credit') {
                $insurance = $this->service->payFromReusableCredit($insurance);
                return response()->json(['message' => translate('seller_order_insurance_paid_from_reusable_credit'), 'seller_order_insurance' => $this->service->payload($insurance)]);
            }

            $gateway = collect(payment_gateways())->firstWhere('key_name', $method);
            if (! (getWebConfig(name: 'digital_payment')['status'] ?? 0) || ! $gateway) throw new DomainException('payment_method_is_not_available');
            $currencyCode = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? $this->getPaymentGatewayCurrencyCode(key: $method, currentCurrency: $request->input('current_currency_code'))
                : (Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD');
            $amount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: (float) $insurance->amount)
                : (float) $insurance->amount;
            $seller = $request->seller;
            $paymentInfo = new PaymentInfo(
                success_hook: 'seller_order_insurance_payment_success', failure_hook: 'seller_order_insurance_payment_fail',
                currency_code: $currencyCode, payment_method: $method, payment_platform: 'app',
                payer_id: $seller->id, receiver_id: '100',
                additional_data: ['seller_id' => $seller->id, 'seller_order_insurance_id' => $insurance->id, 'order_id' => $order->id],
                payment_amount: $amount, external_redirect_link: 'seller-app://orders/' . $order->id,
                attribute: 'seller_order_insurance', attribute_id: $insurance->id,
            );
            $payer = new Payer(trim($seller->f_name . ' ' . $seller->l_name), $seller->email, $seller->phone, '');
            $redirect = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (! $redirect) throw new DomainException('payment_method_is_not_available');
            parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
            if (! empty($query['payment_id'])) $this->service->attachPaymentRequest($insurance, (string) $query['payment_id']);
            return response()->json(['redirect_link' => $redirect, 'payment_pending' => true]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    public function offline(Request $request, int|string $id): JsonResponse
    {
        $request->validate([
            'method_id' => 'required|integer', 'payment_note' => 'nullable|string|max:1000',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        $method = OfflinePaymentMethod::query()->where('id', $request->integer('method_id'))->where('status', 1)->first();
        if (! (getWebConfig(name: 'offline_payment')['status'] ?? 0) || ! $method) return response()->json(['message' => translate('offline_payment_method_not_found')], 422);
        try {
            [, $insurance] = $this->resolve($request, $id);
            if (! $insurance) return response()->json(['message' => translate('seller_order_insurance_is_disabled')], 422);
            $proof = $this->upload(dir: 'seller-order-insurance/payment-proof/', format: 'webp', image: $request->file('payment_proof'));
            $insurance = $this->service->submitOfflinePayment($insurance, [
                'method_id' => $method->id, 'method_name' => $method->method_name,
                'payment_note' => $request->input('payment_note'),
                'payment_proof' => ['image_name' => $proof, 'storage' => config('filesystems.disks.default') ?? 'public'],
                'submitted_at' => now()->toIso8601String(),
            ]);
            return response()->json(['message' => translate('offline_payment_submitted_and_waiting_for_admin_review'), 'seller_order_insurance' => $this->service->payload($insurance)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    private function resolve(Request $request, int|string $id): array
    {
        $seller = $request->seller;
        $reference = (string) $id;
        $order = Order::query()->where(['seller_id' => $seller->id, 'seller_is' => 'seller'])
            ->where(function ($query) use ($reference): void {
                if (ctype_digit($reference)) $query->whereKey((int) $reference);
                else $query->where('seller_restricted_access_token', $reference);
            })->first();
        if (! $order) throw new DomainException('seller_order_insurance_order_not_found');
        if (in_array($order->admin_order_review_status, ['pending_admin_review', 'assignment_in_progress', 'waiting_customer_post_purchase_payment'], true)) {
            throw new DomainException('seller_order_insurance_order_not_found');
        }
        if (in_array($order->commerce_flow_version, [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION], true)
            && ! in_array($order->commerce_flow_status, ['seller_insurance_pending', 'seller_insurance_under_review', 'released_to_seller', 'seller_insurance_paid', 'seller_released', 'fulfillment_in_progress', 'completed', 'cancelled'], true)) {
            throw new DomainException('seller_order_insurance_order_not_found');
        }
        return [$order, $this->service->getOrCreate($order, $seller)];
    }
}
