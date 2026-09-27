<?php

namespace App\Http\Controllers\Vendor\Order;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Services\SellerOrderInsuranceService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function App\Utils\payment_gateways;

class SellerOrderInsurancePaymentController extends Controller
{
    use FileManagerTrait;
    use Payment;
    use PaymentGatewayTrait;

    public function __construct(private readonly SellerOrderInsuranceService $insuranceService) {}

    public function pay(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['payment_method' => 'required|string|max:100', 'current_currency_code' => 'nullable|string|max:10']);
        $seller = auth('seller')->user();
        $order = $this->resolveOrder($seller->id, (string) $id);
        if ($order->commerce_flow_version === config('order_commerce.new_flow_version')
            && ! in_array($order->commerce_flow_status, ['seller_insurance_pending', 'seller_insurance_under_review', 'released_to_seller', 'seller_insurance_paid', 'seller_released'], true)) {
            abort(404);
        }

        try {
            $insurance = $this->insuranceService->getOrCreate($order, $seller);
            if (! $insurance) return redirect()->route('vendor.orders.details', $order->id);
            if ($request->get('payment_method') === 'operating_balance') {
                throw new DomainException('seller_operating_balance_cannot_pay_insurance');
            }
            if ($request->get('payment_method') === 'seller_order_insurance_credit') {
                $this->insuranceService->payFromReusableCredit($insurance);
                ToastMagic::success(translate('seller_order_insurance_paid_from_reusable_credit'));
                return redirect()->route('vendor.orders.details', $order->id);
            }

            $gateway = collect(payment_gateways())->firstWhere('key_name', $request->get('payment_method'));
            if (! (getWebConfig(name: 'digital_payment')['status'] ?? 0) || ! $gateway) {
                throw new DomainException('payment_method_is_not_available');
            }
            $currencyCode = $this->currencyCode($request);
            $amount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: (float) $insurance->amount)
                : (float) $insurance->amount;
            $paymentInfo = new PaymentInfo(
                success_hook: 'seller_order_insurance_payment_success', failure_hook: 'seller_order_insurance_payment_fail',
                currency_code: $currencyCode, payment_method: $request->get('payment_method'), payment_platform: 'web',
                payer_id: $seller->id, receiver_id: '100',
                additional_data: ['seller_id' => $seller->id, 'seller_order_insurance_id' => $insurance->id, 'order_id' => $order->id],
                payment_amount: $amount, external_redirect_link: route('vendor.orders.details', $order->id),
                attribute: 'seller_order_insurance', attribute_id: $insurance->id,
            );
            $payer = new Payer(trim($seller->f_name . ' ' . $seller->l_name), $seller->email, $seller->phone, '');
            $redirect = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (! $redirect) throw new DomainException('payment_method_is_not_available');
            parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
            if (! empty($query['payment_id'])) $this->insuranceService->attachPaymentRequest($insurance, (string) $query['payment_id']);
            return redirect($redirect);
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
            return back();
        }
    }

    public function submitOfflinePayment(Request $request, int|string $id): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'method_id' => 'required|integer', 'payment_note' => 'nullable|string|max:1000',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        if ($validator->fails()) { ToastMagic::error($validator->errors()->first()); return back(); }
        $seller = auth('seller')->user();
        $method = OfflinePaymentMethod::query()->where('id', $request->get('method_id'))->where('status', 1)->first();
        if (! (getWebConfig(name: 'offline_payment')['status'] ?? 0) || ! $method) {
            ToastMagic::error(translate('offline_payment_method_not_found')); return back();
        }
        try {
            $order = $this->resolveOrder($seller->id, (string) $id);
            if ($order->commerce_flow_version === config('order_commerce.new_flow_version')
                && ! in_array($order->commerce_flow_status, ['seller_insurance_pending', 'seller_insurance_under_review', 'released_to_seller', 'seller_insurance_paid', 'seller_released'], true)) {
                abort(404);
            }
            $insurance = $this->insuranceService->getOrCreate($order, $seller);
            $proof = $this->upload(dir: 'seller-order-insurance/payment-proof/', format: 'webp', image: $request->file('payment_proof'));
            $this->insuranceService->submitOfflinePayment($insurance, [
                'method_id' => $method->id, 'method_name' => $method->method_name, 'payment_note' => $request->get('payment_note'),
                'payment_proof' => ['image_name' => $proof, 'storage' => config('filesystems.disks.default') ?? 'public'], 'submitted_at' => now()->toDateTimeString(),
            ]);
            ToastMagic::success(translate('offline_payment_submitted_and_waiting_for_admin_review'));
        } catch (DomainException $exception) { ToastMagic::error(translate($exception->getMessage())); }
        return redirect()->route('vendor.orders.details', $id);
    }

    private function currencyCode(Request $request): string
    {
        if (getWebConfig(name: 'currency_model') === 'multi_currency') {
            return $this->getPaymentGatewayCurrencyCode(key: $request->get('payment_method'), currentCurrency: $request->get('current_currency_code') ?: session('currency_code'));
        }
        return Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD';
    }

    private function resolveOrder(int $sellerId, string $reference): Order
    {
        return Order::query()->where(['seller_id' => $sellerId, 'seller_is' => 'seller'])
            ->where(function ($query) use ($reference): void {
                if (ctype_digit($reference)) $query->whereKey((int) $reference);
                else $query->where('seller_restricted_access_token', $reference);
            })->firstOrFail();
    }
}
