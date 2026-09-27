<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\BaseController;
use App\Models\BusinessSetting;
use App\Models\EgyptShippingZoneRate;
use App\Services\EgyptDispatchOriginResolver;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EgyptShippingZoneRateController extends BaseController
{
    public function index(?Request $request = null, ?string $type = null): RedirectResponse
    {
        return $this->getView();
    }

    public function getView(): RedirectResponse
    {
        // Keep old bookmarks working without exposing a second shipping settings page.
        return redirect()->route('admin.business-settings.shipping-method.index');
    }

    public function store(Request $request): RedirectResponse
    {
        EgyptShippingZoneRate::create($this->validated($request));
        ToastMagic::success(translate('successfully_added'));
        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $rate = EgyptShippingZoneRate::findOrFail($id);
        $rate->update($this->validated($request));
        ToastMagic::success(translate('successfully_updated'));
        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        EgyptShippingZoneRate::findOrFail($id)->delete();
        ToastMagic::success(translate('successfully_deleted'));
        return back();
    }

    public function updateDeliveryPromises(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'normal_delivery_days' => ['required', 'integer', 'min:1', 'max:60'],
            'sigma_delivery_days' => ['required', 'integer', 'min:1', 'max:60', 'lte:normal_delivery_days'],
        ]);

        foreach ([
            'shipping_promise_normal_mode' => 'fixed',
            'shipping_promise_normal_min_days' => $data['normal_delivery_days'],
            'shipping_promise_normal_max_days' => $data['normal_delivery_days'],
            'shipping_promise_sigma_mode' => 'fixed',
            'shipping_promise_sigma_min_days' => $data['sigma_delivery_days'],
            'shipping_promise_sigma_max_days' => $data['sigma_delivery_days'],
        ] as $type => $value) {
            BusinessSetting::query()->updateOrCreate(['type' => $type], ['value' => (string) $value]);
        }

        clearWebConfigCacheKeys();
        app(\App\Services\CustomerThreeStepShippingService::class)->syncMethods();
        ToastMagic::success(translate('successfully_updated'));

        return back();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'governorate' => ['required', 'string', Rule::in(config('egypt.governorates', []))],
            'district' => ['nullable', 'string', 'max:191'],
            'area' => ['nullable', 'string', 'max:191'],
            'street' => ['nullable', 'string', 'max:191'],
            'dispatch_address' => ['nullable', 'string', 'max:191'],
            'normal_cost' => ['required', 'numeric', 'min:0'],
            'normal_available' => ['nullable', 'boolean'],
            'sigma_available' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
        ]);

        $governorate = $data['governorate'];
        $district = $this->clean($data['district'] ?? null);
        $area = $this->clean($data['area'] ?? null);
        $origin = app(EgyptDispatchOriginResolver::class)->resolve(
            governorate: $governorate,
            district: $district,
            area: $area,
            dispatchAddress: $this->clean($data['dispatch_address'] ?? null),
        );

        $normalCost = currencyConverter(amount: $data['normal_cost']);

        return [
            'country_code' => 'EG',
            'governorate' => $governorate,
            'district' => $district,
            'area' => $area,
            'street' => $this->clean($data['street'] ?? null),
            ...$origin,
            'normal_cost' => $normalCost,
            'sigma_cost' => round($normalCost * 3, 3),
            'included_distance_km' => 0,
            'price_per_km' => 0,
            'peak_multiplier' => 1,
            'sigma_surcharge' => 0,
            'normal_available' => (bool) ($data['normal_available'] ?? false),
            'sigma_available' => (bool) ($data['sigma_available'] ?? false),
            'status' => (bool) ($data['status'] ?? false),
        ];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
