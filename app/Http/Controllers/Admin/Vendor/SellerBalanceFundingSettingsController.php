<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\Controller;
use App\Models\OfflinePaymentMethod;
use App\Services\SellerBalanceFundingSettingsService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

use function App\Utils\payment_gateways;

class SellerBalanceFundingSettingsController extends Controller
{
    public function __construct(private readonly SellerBalanceFundingSettingsService $settingsService)
    {
    }

    public function index(): View
    {
        return view('admin-views.vendor.balance-funding-settings.index', [
            'settings' => $this->settingsService->get(),
            'paymentGateways' => payment_gateways(),
            'offlinePaymentMethods' => OfflinePaymentMethod::query()->where('status', 1)->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'min_amount' => 'required|numeric|min:0.01',
            'max_amount' => 'nullable|numeric|min:0',
            'digital_gateways' => 'nullable|array',
            'digital_gateways.*' => 'string|max:100',
            'company_accounts' => 'nullable|array',
            'company_accounts.*.bank_name' => 'nullable|string|max:191',
            'company_accounts.*.account_name' => 'nullable|string|max:191',
            'company_accounts.*.account_number' => 'nullable|string|max:191',
            'company_accounts.*.iban' => 'nullable|string|max:191',
            'company_accounts.*.notes' => 'nullable|string|max:1000',
        ]);

        $gatewayKeys = collect(payment_gateways())->pluck('key_name')->map(fn ($key) => (string) $key)->all();
        $selectedGateways = array_values(array_intersect($validated['digital_gateways'] ?? [], $gatewayKeys));
        $accounts = collect($validated['company_accounts'] ?? [])->map(function (array $account) {
            return collect($account)->map(fn ($value) => is_string($value) ? trim($value) : $value)->all();
        })->filter(fn (array $account) => collect($account)->contains(fn ($value) => $value !== null && $value !== ''))->values()->all();

        try {
            $this->settingsService->update([
                'offline_enabled' => $request->boolean('offline_enabled'),
                'digital_enabled' => $request->boolean('digital_enabled'),
                'min_amount' => $validated['min_amount'],
                'max_amount' => $validated['max_amount'] ?? 0,
                'digital_gateways' => $selectedGateways,
                'company_accounts' => $accounts,
            ]);
            ToastMagic::success(translate('seller_balance_funding_settings_updated'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }
}
