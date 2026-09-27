<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Models\OrderInsurance;
use App\Services\CustomerPostPurchaseInsuranceService;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

use function App\Utils\payment_gateways;

class CustomerOrderInsuranceController extends Controller
{
    use FileManagerTrait;
    use Payment;
    use PaymentGatewayTrait;

    public function __construct(private readonly CustomerPostPurchaseInsuranceService $service) {}

    public function show(Request $request, int|string $id): View|RedirectResponse
    {
        [$order, $insurance] = $this->resolve($id);
        if (! $insurance) {
            return redirect()->route('account-order-details', ['id' => $order->id]);
        }

        return view('web-views.users-profile.order-insurance', [
            'order' => $order,
            'insurance' => $insurance,
            'payload' => $this->service->payload($order),
            'balance' => app(\App\Services\CustomerInsuranceBalanceService::class)->summary((int) auth('customer')->id()),
            'paymentGateways' => collect(),
            'offlineMethods' => OfflinePaymentMethod::query()->where('status', 1)->whereIn('payment_channel', ['wallet', 'instapay'])->get(),
            'digitalPayment' => false,
            'offlinePayment' => (bool) (getWebConfig(name: 'offline_payment')['status'] ?? 0),
        ]);
    }

    public function pay(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['payment_method' => 'required|string|max:100', 'current_currency_code' => 'nullable|string|max:10']);
        [$order, $insurance] = $this->resolve($id);
        if (! $insurance) {
            return redirect()->route('account-order-details', ['id' => $order->id]);
        }

        try {
            if ($request->input('payment_method') === 'customer_insurance_balance') {
                $this->service->payFromInsuranceBalance($insurance);
                ToastMagic::success(translate('customer_order_insurance_paid_successfully'));
                return redirect()->route('account-order-insurance.show', $order->id);
            }

            $gateway = collect(payment_gateways())->firstWhere('key_name', $request->input('payment_method'));
            if (! (getWebConfig(name: 'digital_payment')['status'] ?? 0) || ! $gateway) {
                throw new DomainException('payment_method_is_not_available');
            }
            $currencyCode = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? $this->getPaymentGatewayCurrencyCode(
                    key: $request->input('payment_method'),
                    currentCurrency: $request->input('current_currency_code') ?: session('currency_code'),
                )
                : (Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD');
            $amount = getWebConfig(name: 'currency_model') === 'multi_currency'
                ? usdToAnotherCurrencyConverter(currencyCode: $currencyCode, amount: (float) $insurance->amount)
                : (float) $insurance->amount;
            $customer = auth('customer')->user();
            $payment = new PaymentInfo(
                success_hook: 'customer_order_insurance_payment_success',
                failure_hook: 'customer_order_insurance_payment_fail',
                currency_code: $currencyCode,
                payment_method: $request->input('payment_method'),
                payment_platform: 'web',
                payer_id: $customer->id,
                receiver_id: '100',
                additional_data: ['order_insurance_id' => $insurance->id, 'order_id' => $order->id],
                payment_amount: $amount,
                external_redirect_link: route('account-order-insurance.show', $order->id),
                attribute: 'customer_order_insurance',
                attribute_id: $insurance->id,
            );
            $payer = new Payer(trim($customer->f_name . ' ' . $customer->l_name), $customer->email, $customer->phone, '');
            $url = $this->generate_link($payer, $payment, new Receiver('receiver_name', 'example.png'));
            if (! $url) {
                throw new DomainException('payment_method_is_not_available');
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (! empty($query['payment_id'])) {
                $this->service->attachPaymentRequest($insurance, (string) $query['payment_id']);
            }
            return redirect($url);
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
            return back();
        }
    }

    public function offline(Request $request, int|string $id): RedirectResponse
    {
        $data = $request->validate([
            'method_id' => 'required|integer',
            'payment_note' => 'nullable|string|max:1000',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        [, $insurance] = $this->resolve($id);
        $method = OfflinePaymentMethod::query()->whereKey($data['method_id'])->where('status', 1)->whereIn('payment_channel', ['wallet', 'instapay'])->first();
        if (! $insurance || ! $method || ! (getWebConfig(name: 'offline_payment')['status'] ?? 0)) {
            ToastMagic::error(translate('offline_payment_method_not_found'));
            return back();
        }
        try {
            $proof = $this->upload('customer-order-insurance/payment-proof/', 'webp', $request->file('payment_proof'));
            $this->service->submitOfflinePayment($insurance, [
                'method_id' => $method->id,
                'method_name' => $method->method_name,
                'payment_note' => $data['payment_note'] ?? null,
                'payment_proof' => ['image_name' => $proof, 'storage' => config('filesystems.disks.default') ?? 'public'],
                'submitted_at' => now()->toIso8601String(),
            ]);
            ToastMagic::success(translate('offline_payment_submitted_and_waiting_for_admin_review'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    public function support(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['message' => 'nullable|string|max:3000']);
        [, $insurance] = $this->resolve($id);
        if (! $insurance) {
            return back();
        }
        $ticket = $this->service->openSupportTicket($insurance, $request->input('message'));
        return redirect()->route('support-ticket.index', ['id' => $ticket->id]);
    }

    public function decline(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:2000']);
        [$order, $insurance] = $this->resolve($id);
        if ($insurance) {
            try {
                $this->service->decline($insurance, $request->input('reason'));
                ToastMagic::success(translate('purchase_refund_scheduled_successfully'));
            } catch (DomainException $exception) {
                ToastMagic::error(translate($exception->getMessage()));
            }
        }
        return redirect()->route('account-order-details', ['id' => $order->id]);
    }

    private function resolve(int|string $id): array
    {
        $order = Order::query()->with('insurance')->whereKey($id)
            ->where('customer_id', auth('customer')->id())->where('is_guest', false)->firstOrFail();
        return [$order, $order->insurance];
    }
}
