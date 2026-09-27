<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\BaseController;
use App\Models\SellerPackageSubscription;
use App\Services\SellerPackagePurchaseService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SellerPackagePaymentController extends BaseController
{
    public function __construct(
        private readonly SellerPackagePurchaseService $purchaseService,
    ) {}

    public function index(?Request $request, ?string $type = null): View
    {
        $request ??= request();
        $subscriptions = SellerPackageSubscription::query()
            ->with(['seller:id,f_name,l_name,email,phone', 'package:id,name'])
            ->where('payment_method', 'offline_payment')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->when(! $request->filled('status'), fn ($query) => $query->whereIn('status', [
                SellerPackageSubscription::STATUS_PENDING_REVIEW,
                SellerPackageSubscription::STATUS_ACTIVE,
                SellerPackageSubscription::STATUS_REJECTED,
            ]))
            ->when($request->filled('searchValue'), function ($query) use ($request) {
                $search = $request->get('searchValue');
                $query->where(function ($query) use ($search) {
                    $query->where('package_name', 'like', "%{$search}%")
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

        return view('admin-views.vendor.package.offline-payment-reviews', compact('subscriptions'));
    }

    public function approve(Request $request, int|string $id): RedirectResponse
    {
        $request->validate([
            'payment_reference' => 'nullable|string|max:191',
            'review_note' => 'nullable|string|max:1000',
        ]);
        $subscription = SellerPackageSubscription::query()
            ->where('payment_method', 'offline_payment')
            ->where('payment_status', 'unpaid')
            ->where('status', SellerPackageSubscription::STATUS_PENDING_REVIEW)
            ->find($id);
        if (! $subscription) {
            ToastMagic::error(translate('seller_package_offline_review_not_found'));

            return back();
        }

        $result = $this->purchaseService->markPaid($subscription, [
            'payment_method' => 'offline_payment',
            'transaction_id' => $request->get('payment_reference') ?: ('seller-package-offline-'.$subscription->id),
            'payment_amount' => (float) $subscription->paid_package_price,
            'currency_code' => getCurrencyCode(type: 'default'),
        ], auth('admin')->id(), $request->get('review_note'));
        if (($result['status'] ?? 0) !== 1) {
            ToastMagic::error(translate($result['message'] ?? 'seller_package_approval_failed'));

            return back();
        }

        ToastMagic::success(translate('seller_package_offline_payment_approved'));

        return back();
    }

    public function reject(Request $request, int|string $id): RedirectResponse
    {
        $request->validate(['review_note' => 'required|string|max:1000']);
        $subscription = SellerPackageSubscription::query()
            ->where('payment_method', 'offline_payment')
            ->where('payment_status', 'unpaid')
            ->where('status', SellerPackageSubscription::STATUS_PENDING_REVIEW)
            ->find($id);
        if (! $subscription) {
            ToastMagic::error(translate('seller_package_offline_review_not_found'));

            return back();
        }

        try {
            $this->purchaseService->rejectOfflinePayment(
                $subscription,
                auth('admin')->id(),
                $request->get('review_note')
            );
            ToastMagic::success(translate('seller_package_offline_payment_rejected'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }
}
