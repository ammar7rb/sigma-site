<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\Controller;
use App\Models\SellerBalanceDeposit;
use App\Services\SellerBalanceDepositService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SellerBalanceDepositController extends Controller
{
    public function __construct(private readonly SellerBalanceDepositService $depositService)
    {
    }

    public function index(Request $request): View
    {
        $deposits = SellerBalanceDeposit::query()->with(['seller:id,f_name,l_name,email,phone', 'offlinePaymentMethod:id,method_name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->get('source')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->get('q').'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('transaction_reference', 'like', $term)->orWhere('payment_request_id', 'like', $term)
                        ->orWhereHas('seller', fn ($seller) => $seller->where('email', 'like', $term)->orWhere('phone', 'like', $term));
                });
            })->latest('id')->paginate(getWebConfig(name: 'pagination_limit'))->appends($request->query());
        return view('admin-views.vendor.balance-deposits.index', compact('deposits'));
    }

    public function approve(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['payment_reference' => 'nullable|string|max:191', 'review_note' => 'nullable|string|max:1000']);
        $deposit = SellerBalanceDeposit::query()->where('source', SellerBalanceDeposit::SOURCE_OFFLINE)->where('status', SellerBalanceDeposit::STATUS_PENDING)->find($id);
        if (!$deposit) { ToastMagic::error(translate('seller_balance_deposit_review_not_found')); return back(); }
        try {
            $this->depositService->markPaid($deposit, ['payment_method' => 'offline_payment', 'transaction_id' => $request->get('payment_reference') ?: 'seller-balance-offline-'.$deposit->id, 'payment_amount' => $deposit->amount, 'currency_code' => $deposit->currency_code], auth('admin')->id(), $request->get('review_note'));
            ToastMagic::success(translate('seller_balance_deposit_approved'));
        } catch (DomainException $exception) { ToastMagic::error(translate($exception->getMessage())); }
        return back();
    }

    public function reject(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['review_note' => 'required|string|max:1000']);
        $deposit = SellerBalanceDeposit::query()->where('source', SellerBalanceDeposit::SOURCE_OFFLINE)->where('status', SellerBalanceDeposit::STATUS_PENDING)->find($id);
        if (!$deposit) { ToastMagic::error(translate('seller_balance_deposit_review_not_found')); return back(); }
        try { $this->depositService->reject($deposit, auth('admin')->id(), $request->get('review_note')); ToastMagic::success(translate('seller_balance_deposit_rejected')); }
        catch (DomainException $exception) { ToastMagic::error(translate($exception->getMessage())); }
        return back();
    }

    public function reverse(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:1000']);
        $deposit = SellerBalanceDeposit::query()->whereKey($id)->firstOrFail();
        try { $this->depositService->reverse($deposit, auth('admin')->id(), $request->get('reason')); ToastMagic::success(translate('seller_balance_deposit_reversed')); }
        catch (DomainException $exception) { ToastMagic::error(translate($exception->getMessage())); }
        return back();
    }
}
