<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\Controller;
use App\Models\VendorWithdrawMethodInfo;
use App\Services\SellerWithdrawalMethodService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerWithdrawMethodApprovalController extends Controller
{
    public function __construct(private readonly SellerWithdrawalMethodService $service)
    {
    }

    public function index(Request $request): View
    {
        $query = VendorWithdrawMethodInfo::query()->with(['withdraw_method', 'seller'])
            ->when($request->filled('status'), fn ($q) => $q->where('approval_status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->string('q') . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('method_name', 'like', $term)->orWhere('method_type', 'like', $term)
                        ->orWhereHas('seller', fn ($seller) => $seller->where('email', 'like', $term));
                });
            })
            ->latest('id');

        return view('admin-views.vendor.withdraw-method-approvals.index', [
            'methods' => $query->paginate(getWebConfig('pagination_limit'))->appends($request->query()),
        ]);
    }

    public function decision(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:2000',
        ]);
        $method = VendorWithdrawMethodInfo::query()->findOrFail($id);
        if ($data['decision'] === 'reject' && trim((string) ($data['reason'] ?? '')) === '') {
            return back()->withErrors(['reason' => translate('withdrawal_rejection_reason_required')]);
        }
        if ($data['decision'] === 'approve') {
            $this->service->approve($method, auth('admin')->id());
            ToastMagic::success(translate('withdrawal_method_approved'));
        } else {
            $this->service->reject($method, auth('admin')->id(), $data['reason']);
            ToastMagic::success(translate('withdrawal_method_rejected'));
        }
        return back();
    }
}
