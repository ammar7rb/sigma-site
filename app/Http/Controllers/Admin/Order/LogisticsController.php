<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\BaseController;
use App\Models\Order;
use App\Models\ShippingMethod;
use App\Services\OrderLogisticsService;
use App\Services\OrderShippingDecisionService;
use App\Services\OrderWorkflowService;
use App\Models\BusinessSetting;
use App\Models\OrderOperationalAlert;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class LogisticsController extends BaseController
{
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|RedirectResponse|JsonResponse|callable|null
    {
        $request ??= request();
        $query = Order::query()
            ->with(['customer:id,f_name,l_name,phone', 'seller:id,f_name,l_name,phone'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $value = trim((string) $request->q);
                $query->where(function ($query) use ($value) {
                    $query->where('id', $value)
                        ->orWhere('order_group_id', 'like', "%{$value}%")
                        ->orWhere('shipment_reference', 'like', "%{$value}%")
                        ->orWhere('third_party_delivery_tracking_id', 'like', "%{$value}%")
                        ->orWhere('return_tracking_number', 'like', "%{$value}%")
                        ->orWhere('delivery_service_name', 'like', "%{$value}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('phone', 'like', "%{$value}%"))
                        ->orWhereHas('seller', fn ($seller) => $seller->where('phone', 'like', "%{$value}%"));
                });
            })
            ->when($request->filled('shipping_status'), fn ($query) => $query->where('shipping_operational_status', $request->shipping_status))
            ->when($request->filled('responsible_party'), fn ($query) => $query->where('shipping_responsible_party', $request->responsible_party));

        $summaryBase = Order::query();
        $summary = [
            'pending_assignment' => (clone $summaryBase)->where('shipping_operational_status', 'pending_assignment')->count(),
            'in_transit' => (clone $summaryBase)->where('shipping_operational_status', 'in_transit')->count(),
            'delivered' => (clone $summaryBase)->where('shipping_operational_status', 'delivered')->count(),
            'returns' => (clone $summaryBase)->whereIn('shipping_operational_status', ['return_in_transit', 'returned'])->count(),
            'seller_delay_alerts' => OrderOperationalAlert::query()->where('alert_type', 'seller_delay')->whereNull('resolved_at')->count(),
        ];

        return view('admin-views.order.logistics.index', [
            'orders' => $query->latest('id')->paginate(getWebConfig('pagination_limit'))->appends($request->query()),
            'summary' => $summary,
            'statuses' => ['pending_assignment', 'assigned', 'preparing', 'in_transit', 'delivered', 'return_in_transit', 'returned', 'closed'],
            'responsibleParties' => OrderLogisticsService::RESPONSIBLE_PARTIES,
            'adminShippingMethods' => ShippingMethod::query()
                ->where('creator_type', 'admin')
                ->where('status', 1)
                ->orderBy('title')
                ->get(['id', 'title', 'cost']),
            'delaySettings' => $this->delaySettings(),
            'soundEnabled' => filter_var(BusinessSetting::query()->where('type', 'seller_order_sound_alert_enabled')->value('value') ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function quickAssign(Request $request, string|int $id, OrderShippingDecisionService $service): RedirectResponse
    {
        $data = $request->validate([
            'assignment' => ['required', 'string', 'max:100'],
        ]);
        $order = Order::query()->find($id);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return back();
        }

        $service->quickAssign(
            $order,
            $data['assignment'],
            auth('admin')->id(),
            translate('quick_assignment_from_logistics_center')
        );
        ToastMagic::success(translate('shipping_decision_saved_successfully'));
        return back();
    }

    public function registerReturn(Request $request, string|int $id, OrderLogisticsService $service): RedirectResponse
    {
        $data = $request->validate([
            'return_responsible_party' => ['required', 'in:company,seller,carrier,customer'],
            'return_shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'return_tracking_number' => ['nullable', 'string', 'max:255'],
            'return_reason' => ['required', 'string', 'max:5000'],
        ]);

        $order = Order::query()->find($id);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return back();
        }

        $service->registerReturn($order, $data, auth('admin')->id());
        ToastMagic::success(translate('return_logistics_registered_successfully'));
        return back();
    }

    public function overrideQueue(Request $request, string|int $id, OrderWorkflowService $workflow): RedirectResponse
    {
        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:1', 'max:9999'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->overrideQueue(Order::query()->findOrFail($id), (int) $data['priority'], (int) auth('admin')->id(), $data['reason']);
        ToastMagic::success(translate('seller_order_queue_updated'));
        return back();
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pending_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'confirmed_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'processing_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'out_for_delivery_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'sound_enabled' => ['nullable', 'boolean'],
        ]);
        BusinessSetting::query()->updateOrCreate(['type' => 'seller_order_delay_minutes'], ['value' => json_encode([
            'pending' => (int) $data['pending_minutes'],
            'confirmed' => (int) $data['confirmed_minutes'],
            'processing' => (int) $data['processing_minutes'],
            'out_for_delivery' => (int) $data['out_for_delivery_minutes'],
        ])]);
        BusinessSetting::query()->updateOrCreate(['type' => 'seller_order_sound_alert_enabled'], ['value' => (int) ($data['sound_enabled'] ?? false)]);
        ToastMagic::success(translate('order_operations_settings_updated'));
        return back();
    }

    private function delaySettings(): array
    {
        $value = BusinessSetting::query()->where('type', 'seller_order_delay_minutes')->value('value');
        $value = is_string($value) ? json_decode($value, true) : $value;
        return array_merge(['pending' => 30, 'confirmed' => 60, 'processing' => 180, 'out_for_delivery' => 1440], is_array($value) ? $value : []);
    }
}
