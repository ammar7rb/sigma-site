<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerBalanceDeposit;
use App\Services\CustomerBalanceDepositService;
use Illuminate\Http\Request;

class CustomerBalanceDepositController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['status' => 'nullable|in:pending,paid,rejected']);
        $deposits = CustomerBalanceDeposit::with('customer:id,f_name,l_name')
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();
        return view('admin-views.customer.wallet.deposits', compact('deposits'));
    }

    public function review(Request $request, int $deposit, CustomerBalanceDepositService $service)
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:1000|required_if:decision,reject']);
        try {
            $service->review(CustomerBalanceDeposit::findOrFail($deposit), $data['decision'], (int) auth('admin')->id(), $data['note'] ?? null);
        } catch (\DomainException $exception) {
            return back()->withErrors(['deposit' => translate($exception->getMessage())]);
        }
        return back()->with('deposit_message', translate('deposit_review_saved'));
    }

    public function proof(int $deposit)
    {
        return app(CustomerBalanceDepositService::class)->receiptResponse(CustomerBalanceDeposit::findOrFail($deposit));
    }
}
