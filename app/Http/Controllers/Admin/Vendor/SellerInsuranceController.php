<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\BaseController;
use App\Models\SellerInsurance;
use App\Services\SellerInsuranceService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SellerInsuranceController extends BaseController
{
    public function __construct(
        private readonly SellerInsuranceService $sellerInsuranceService,
    ) {}

    public function index(?Request $request, ?string $type = null): View
    {
        $request ??= request();
        $insurances = SellerInsurance::query()
            ->with(['seller:id,f_name,l_name,email,phone', 'reviewedByAdmin:id,name'])
            ->where('payment_method', 'offline_payment')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->when(! $request->filled('status'), fn ($query) => $query->whereIn('status', [
                SellerInsurance::STATUS_PENDING_REVIEW,
                SellerInsurance::STATUS_PAID,
                SellerInsurance::STATUS_REJECTED,
            ]))
            ->when($request->filled('searchValue'), function ($query) use ($request) {
                $search = $request->get('searchValue');
                $query->where(function ($query) use ($search) {
                    $query->where('transaction_id', 'like', "%{$search}%")
                        ->orWhere('payment_reference', 'like', "%{$search}%")
                        ->orWhereHas('seller', function ($query) use ($search) {
                            $query->where('f_name', 'like', "%{$search}%")
                                ->orWhere('l_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate(getWebConfig(name: 'pagination_limit'))
            ->appends($request->query());

        return view('admin-views.vendor.insurance.offline-payment-reviews', compact('insurances'));
    }

    public function approve(Request $request, int|string $id): RedirectResponse
    {
        $request->validate([
            'payment_reference' => 'nullable|string|max:191',
            'review_note' => 'nullable|string|max:1000',
        ]);
        $insurance = SellerInsurance::query()
            ->where('payment_method', 'offline_payment')
            ->where('payment_status', 'unpaid')
            ->where('status', SellerInsurance::STATUS_PENDING_REVIEW)
            ->find($id);
        if (! $insurance) {
            ToastMagic::error(translate('seller_insurance_offline_review_not_found'));

            return back();
        }

        $result = $this->sellerInsuranceService->markPaid($insurance, [
            'payment_method' => 'offline_payment',
            'transaction_id' => $request->get('payment_reference') ?: ('seller-insurance-offline-'.$insurance->id),
            'payment_amount' => (float) $insurance->amount,
            'currency_code' => getCurrencyCode(type: 'default'),
        ], auth('admin')->id(), $request->get('review_note'));

        if (($result['status'] ?? 0) !== 1) {
            ToastMagic::error(translate($result['message'] ?? 'seller_insurance_approval_failed'));

            return back();
        }

        ToastMagic::success(translate('seller_insurance_offline_payment_approved'));

        return back();
    }

    public function reject(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['review_note' => 'required|string|max:1000']);
        $insurance = SellerInsurance::query()
            ->where('payment_method', 'offline_payment')
            ->where('payment_status', 'unpaid')
            ->where('status', SellerInsurance::STATUS_PENDING_REVIEW)
            ->find($id);
        if (! $insurance) {
            ToastMagic::error(translate('seller_insurance_offline_review_not_found'));

            return back();
        }

        try {
            $this->sellerInsuranceService->rejectOfflinePayment(
                $insurance,
                auth('admin')->id(),
                $request->get('review_note')
            );
            ToastMagic::success(translate('seller_insurance_offline_payment_rejected'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }
}
