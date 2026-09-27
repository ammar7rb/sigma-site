<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerBalanceDeposit;
use App\Models\Currency;
use App\Models\OfflinePaymentMethod;
use App\Utils\Convert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerBalanceDepositController extends Controller
{
    public function store(Request $request)
    {
        $customerId = $request->is('api/*') ? $request->user()->id : auth('customer')->id();
        $success = fn () => $request->is('api/*')
            ? response()->json(['message' => translate('deposit_submitted_for_review')], 200)
            : redirect()->route('customer-balances')->with('deposit_message', translate('deposit_submitted_for_review'));
        abort_unless(getWebConfig('add_funds_to_wallet') == 1 && (getWebConfig('offline_payment')['status'] ?? 0), 403);
        $data = $request->validate([
            'wallet_type' => 'required|in:purchase,insurance', 'amount' => 'required|numeric|gt:0',
            'method_id' => ['required', 'integer', Rule::exists('offline_payment_methods', 'id')->where(fn ($query) => $query->where('status', 1)->whereIn('payment_channel', ['wallet', 'instapay']))],
            'request_key' => 'required|uuid', 'payment_reference' => 'required|string|max:191',
            'payment_note' => 'nullable|string|max:1000', 'method_information' => 'nullable|array',
            'method_information.*' => 'nullable|string|max:255',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        abort_unless($data['wallet_type'] === 'insurance' || getWebConfig('wallet_status') == 1, 403);
        $requestKey = $customerId . ':' . $data['request_key'];
        if (CustomerBalanceDeposit::where('request_key', $requestKey)->exists()) return $success();
        $method = OfflinePaymentMethod::where('status', 1)->whereIn('payment_channel', ['wallet', 'instapay'])->findOrFail($data['method_id']);
        $information = [];
        foreach ($method->method_informations ?? [] as $field) {
            $key = $field['customer_input'] ?? '';
            if ($key === '' || $key === 'payment_screenshot') continue;
            $value = trim($data['method_information'][$key] ?? '');
            if (($field['is_required'] ?? false) && $value === '') {
                throw \Illuminate\Validation\ValidationException::withMessages(['method_information' => translate('required_offline_payment_information_is_missing')]);
            }
            $information[$key] = $value;
        }
        $currency = getWebConfig('currency_model') === 'multi_currency'
            ? ($request->is('api/*') ? $request->validate(['currency_code' => 'required|exists:currencies,code'])['currency_code'] : session('currency_code', 'USD')) : Currency::findOrFail(getWebConfig('system_default_currency'))->code;
        $amount = (float) Convert::usdPaymentModule($data['amount'], $currency);
        $minimum = (float) (getWebConfig('minimum_add_fund_amount') ?? 0);
        $maximum = (float) (getWebConfig('maximum_add_fund_amount') ?? 0);
        if (!is_finite($amount) || $amount <= 0 || $amount < $minimum || ($maximum > 0 && $amount > $maximum)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => translate('invalid_deposit_amount')]);
        }
        $request->validate(['payment_reference' => [Rule::unique('customer_balance_deposits')->where('offline_payment_method_id', $method->id)]]);
        $path = 'customer-balance-deposits/' . \Illuminate\Support\Str::uuid() . '.enc';
        Storage::disk('local')->put($path, \Illuminate\Support\Facades\Crypt::encryptString(file_get_contents($request->file('payment_proof')->getRealPath())));
        try {
            CustomerBalanceDeposit::create([
                'customer_id' => $customerId, 'wallet_type' => $data['wallet_type'],
                'amount' => round($amount, 3), 'submitted_amount' => $data['amount'], 'currency_code' => $currency,
                'offline_payment_method_id' => $method->id, 'method_name' => $method->method_name,
                'method_information' => $information, 'payment_reference' => $data['payment_reference'],
                'payment_proof' => $path, 'payment_note' => $data['payment_note'] ?? null, 'request_key' => $requestKey,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            if (CustomerBalanceDeposit::where('request_key', $requestKey)->exists()) return $success();
            throw $exception;
        }
        return $success();
    }

    public function proof(int $deposit)
    {
        $record = CustomerBalanceDeposit::where('customer_id', auth('customer')->id())->findOrFail($deposit);
        return app(\App\Services\CustomerBalanceDepositService::class)->receiptResponse($record);
    }
}
