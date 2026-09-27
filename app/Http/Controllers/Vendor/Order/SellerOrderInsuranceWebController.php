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
use App\Services\PostPurchaseInvoiceService;
use App\Support\Commerce\OrderCommerceState;
use App\Traits\FileManagerTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

use function App\Utils\payment_gateways;

/** Web counterpart of the restricted seller-order-insurance API contract. */
class SellerOrderInsuranceWebController extends Controller
{
    use FileManagerTrait;
    use Payment;
    use PaymentGatewayTrait;

    public function __construct(private readonly SellerOrderInsuranceService $insuranceService) {}

    public function pending(Request $request): View
    {
        $seller = auth('seller')->user();
        $search = trim((string) $request->input('q', ''));
        $orders = Order::query()
            ->with('sellerOrderInsurance')
            ->where(['seller_id' => $seller->id, 'seller_is' => 'seller'])
            ->whereIn('commerce_flow_version', [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION])
            ->whereIn('commerce_flow_status', [
                OrderCommerceState::SELLER_INSURANCE_PENDING,
                OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW,
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $suffix = ltrim($search, '*');
                if (ctype_digit($suffix)) {
                    $query->where('id', 'like', '%'.$suffix);
                }
            })
            ->latest('id')
            ->paginate((int) getWebConfig(name: 'pagination_limit'))
            ->withQueryString();

        return view('vendor-views.order.insurance-pending', compact('orders'));
    }

    public function pay(Request $request, string $reference): RedirectResponse
    {
        $request->validate(['payment_method' => 'required|string|max:100']);
        try {
            [$order, $insurance] = $this->resolve($reference);
            $method = (string) $request->input('payment_method');
            if ($method === 'operating_balance') {
                throw new DomainException('seller_operating_balance_cannot_pay_insurance');
            }
            if ($method === 'seller_order_insurance_credit') {
                $this->insuranceService->payFromReusableCredit($insurance);
                ToastMagic::success(translate('seller_order_insurance_paid_from_reusable_credit'));

                return redirect()->route('vendor.orders.details', $order->id);
            }

            $gateway = collect(payment_gateways())->firstWhere('key_name', $method);
            if (! (getWebConfig(name: 'digital_payment')['status'] ?? 0) || ! $gateway) {
                throw new DomainException('payment_method_is_not_available');
            }
            $currencyCode = Currency::find(getWebConfig(name: 'system_default_currency'))?->code ?: 'USD';
            $seller = auth('seller')->user();
            $paymentInfo = new PaymentInfo(
                success_hook: 'seller_order_insurance_payment_success',
                failure_hook: 'seller_order_insurance_payment_fail',
                currency_code: $currencyCode,
                payment_method: $method,
                payment_platform: 'web',
                payer_id: $seller->id,
                receiver_id: '100',
                additional_data: ['seller_id' => $seller->id, 'seller_order_insurance_id' => $insurance->id, 'order_id' => $order->id],
                payment_amount: (float) $insurance->amount,
                external_redirect_link: route('vendor.orders.details', $order->seller_restricted_access_token ?: $order->id),
                attribute: 'seller_order_insurance',
                attribute_id: $insurance->id,
            );
            $payer = new Payer(trim($seller->f_name.' '.$seller->l_name), $seller->email, $seller->phone, '');
            $redirect = $this->generate_link($payer, $paymentInfo, new Receiver('receiver_name', 'example.png'));
            if (! $redirect) {
                throw new DomainException('payment_method_is_not_available');
            }
            parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
            if (! empty($query['payment_id'])) {
                $this->insuranceService->attachPaymentRequest($insurance, (string) $query['payment_id']);
            }

            return redirect($redirect);
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));

            return back();
        }
    }

    public function offline(Request $request, string $reference): RedirectResponse
    {
        $request->validate([
            'method_id' => 'required|integer',
            'method_information' => 'nullable|array',
            'payment_reference' => 'required|string|max:191',
            'payment_note' => 'nullable|string|max:1000',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        try {
            $method = OfflinePaymentMethod::query()->whereKey($request->integer('method_id'))->where('status', 1)->whereIn('payment_channel', ['wallet', 'instapay'])->first();
            if (! (getWebConfig(name: 'offline_payment')['status'] ?? 0) || ! $method) {
                throw new DomainException('offline_payment_method_not_found');
            }
            $methodInformation = (array) $request->input('method_information', []);
            foreach ((array) ($method->method_informations ?? []) as $field) {
                $key = (string) ($field['customer_input'] ?? '');
                if ($key === '' || $key === 'payment_screenshot') continue;
                if (($field['is_required'] ?? false) && blank($methodInformation[$key] ?? null)) {
                    throw new DomainException('required_offline_payment_information_is_missing');
                }
            }
            [, $insurance] = $this->resolve($reference);
            $proof = $this->upload(dir: 'seller-order-insurance/payment-proof/', format: 'webp', image: $request->file('payment_proof'));
            $this->insuranceService->submitOfflinePayment($insurance, [
                'method_id' => $method->id,
                'method_name' => $method->method_name,
                'method_information' => $methodInformation,
                'payment_reference' => $request->input('payment_reference'),
                'payment_note' => $request->input('payment_note'),
                'payment_proof' => ['image_name' => $proof, 'storage' => config('filesystems.disks.default') ?? 'public'],
                'submitted_at' => now()->toIso8601String(),
            ]);
            ToastMagic::success(translate('offline_payment_submitted_and_waiting_for_admin_review'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }

    /** @return array{0: Order, 1: \App\Models\SellerOrderInsurance} */
    private function resolve(string $reference): array
    {
        $seller = auth('seller')->user();
        $order = Order::query()->where(['seller_id' => $seller->id, 'seller_is' => 'seller'])
            ->where(function ($query) use ($reference): void {
                if (ctype_digit($reference)) {
                    $query->whereKey((int) $reference);
                } else {
                    $query->where('seller_restricted_access_token', $reference);
                }
            })->first();
        if (! $order) {
            throw new DomainException('seller_order_insurance_order_not_found');
        }

        return [$order, $this->insuranceService->getOrCreate($order, $seller)];
    }
}
