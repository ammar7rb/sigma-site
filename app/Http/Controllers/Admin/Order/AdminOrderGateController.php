<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderRiskInvestigation;
use App\Models\ShippingMethod;
use App\Services\AdminOrderGateService;
use App\Services\PostPurchaseInvoiceService;
use App\Services\SellerShippingResponseService;
use App\Support\Commerce\OrderCommerceState;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminOrderGateController extends Controller
{
    public function __construct(private readonly AdminOrderGateService $service) {}

    public function index(Request $request): View
    {
        $query = Order::query()->with(['customer', 'seller.shop', 'insurance', 'sellerOrderInsurance', 'riskInvestigations'])
            ->whereIn('commerce_flow_version', [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION])
            ->whereIn('commerce_flow_status', [
                OrderCommerceState::PENDING_ADMIN_REVIEW,
                OrderCommerceState::ADMIN_ASSIGNMENT_IN_PROGRESS,
                OrderCommerceState::UNDER_INVESTIGATION,
            ])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $value = trim((string) $request->input('q'));
                $id = preg_replace('/\D+/', '', $value);
                $query->where(function ($nested) use ($value, $id): void {
                    if ($id !== '') $nested->orWhere('id', (int) $id);
                    $nested->orWhere('transaction_ref', 'like', "%{$value}%");
                });
            })
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->input('payment_status')))
            ->when($request->filled('customer_insurance'), function ($q) use ($request): void {
                $status = $request->input('customer_insurance');
                if ($status === 'none') $q->whereDoesntHave('insurance');
                else $q->whereHas('insurance', fn ($insurance) => $insurance->where('payment_status', $status));
            })
            ->when($request->filled('shipping_status'), fn ($q) => $q->where('shipping_assignment_status', $request->input('shipping_status')))
            ->when($request->filled('risk_level'), fn ($q) => $q->whereHas('riskInvestigations', fn ($risk) => $risk->where('status', 'open')->where('risk_level', $request->input('risk_level'))))
            ->when($request->input('deadline') === 'overdue', fn ($q) => $q->whereHas('insurance', fn ($insurance) => $insurance->where('payment_due_at', '<', now())))
            ->latest('id');

        $orders = $query->paginate(25)->withQueryString();
        $counts = [
            'waiting' => Order::query()->whereIn('commerce_flow_version', [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION])->where('commerce_flow_status', OrderCommerceState::PENDING_ADMIN_REVIEW)->count(),
            'assignment' => Order::query()->whereIn('commerce_flow_version', [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION])->where('commerce_flow_status', OrderCommerceState::ADMIN_ASSIGNMENT_IN_PROGRESS)->count(),
            'investigations' => OrderRiskInvestigation::query()->where('status', 'open')->count(),
        ];
        return view('admin-views.order.admin-gate.index', compact('orders', 'counts'));
    }

    public function show(int $id): View
    {
        $order = Order::query()->with([
            'customer', 'seller.shop', 'insurance', 'sellerOrderInsurance.decisions',
            'shippingDecisions', 'adminGateDecisions.admin', 'riskInvestigations.messages', 'orderDetails', 'postPurchaseInvoice',
        ])->findOrFail($id);
        $suggestion = $this->service->sellerInsuranceSuggestion($order);
        $shippingMethods = ShippingMethod::query()->where('creator_type', 'admin')->where('status', 1)->orderBy('title')->get();
        return view('admin-views.order.admin-gate.show', compact('order', 'suggestion', 'shippingMethods'));
    }

    public function saveAssignment(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'seller_insurance_mode' => ['required', 'in:default,manual,waive'],
            'seller_insurance_calculation_type' => ['nullable', 'required_if:seller_insurance_mode,manual', 'in:percentage,fixed'],
            'seller_insurance_calculation_value' => ['nullable', 'required_if:seller_insurance_mode,manual', 'numeric', 'min:0'],
            'seller_insurance_override_reason' => ['nullable', 'required_unless:seller_insurance_mode,default', 'string', 'max:5000'],
            'shipping_mode' => ['required', 'in:admin_shipping,seller_shipping'],
            'shipping_method_id' => ['nullable', 'integer'],
            'shipping_customer_cost' => ['nullable', 'numeric', 'min:0'],
            'shipping_seller_cost' => ['nullable', 'numeric', 'min:0'],
            'shipping_seller_entitlement' => ['nullable', 'numeric', 'min:0'],
            'shipping_service_name' => ['nullable', 'string', 'max:255'],
            'shipping_tracking_number' => ['nullable', 'string', 'max:255'],
            'shipping_expected_delivery_date' => ['nullable', 'date'],
            'shipping_instructions' => ['nullable', 'string', 'max:5000'],
            'decision_note' => ['required', 'string', 'max:5000'],
        ]);
        try {
            $this->service->saveAssignment(Order::query()->findOrFail($id), $data, auth('admin')->id());
            ToastMagic::success(translate('admin_order_assignment_saved'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    public function release(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        try {
            $this->service->releaseToSeller(Order::query()->findOrFail($id), $data['note'], auth('admin')->id());
            ToastMagic::success(translate('order_released_to_seller_successfully'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    public function openInvestigation(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'risk_level' => ['required', 'in:low,medium,high,critical'],
            'reason' => ['required', 'string', 'max:5000'],
            'evidence' => ['nullable', 'array'],
        ]);
        try {
            $this->service->openInvestigation(Order::query()->findOrFail($id), $data, auth('admin')->id());
            ToastMagic::success(translate('order_investigation_opened'));
        } catch (\Throwable $exception) { ToastMagic::error(translate($exception->getMessage())); }
        return back();
    }

    public function messageInvestigation(Request $request, int $investigationId): RedirectResponse
    {
        $data = $request->validate(['recipient_type' => ['required', 'in:customer,seller'], 'message' => ['required', 'string', 'max:5000']]);
        try {
            $this->service->sendInvestigationMessage(OrderRiskInvestigation::query()->findOrFail($investigationId), $data['recipient_type'], $data['message'], auth('admin')->id());
            ToastMagic::success(translate('investigation_message_sent'));
        } catch (\Throwable $exception) { ToastMagic::error(translate($exception->getMessage())); }
        return back();
    }

    public function contactSeller(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);

        try {
            app(SellerShippingResponseService::class)->sendFollowUp(
                Order::query()->findOrFail($id),
                $data['message'],
                auth('admin')->id(),
            );
            ToastMagic::success(translate('seller_shipping_follow_up_sent'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }

    public function resolveInvestigation(Request $request, int $investigationId): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:resume,cancel_and_refund,confiscate_customer_and_refund,confiscate_seller_and_resume,confiscate_both_and_refund'],
            'note' => ['required', 'string', 'max:5000'],
        ]);
        try {
            $this->service->resolveInvestigation(OrderRiskInvestigation::query()->findOrFail($investigationId), $data['action'], $data['note'], auth('admin')->id());
            ToastMagic::success(translate('order_investigation_resolved'));
        } catch (\Throwable $exception) { ToastMagic::error(translate($exception->getMessage())); }
        return back();
    }
}
