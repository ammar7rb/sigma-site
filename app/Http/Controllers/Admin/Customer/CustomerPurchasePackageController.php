<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\CustomerPurchasePackageRequest;
use App\Models\CustomerActivationInvoice;
use App\Models\CustomerPurchasePackage;
use App\Models\User;
use App\Services\CustomerActivationInvoiceService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerPurchasePackageController extends BaseController
{
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse|JsonResponse
    {
        $query = CustomerPurchasePackage::query()
            ->with('customer:id,f_name,l_name,email,phone')
            ->withCount('subscriptions')
            ->when($request?->filled('searchValue'), function ($query) use ($request) {
                $search = $request->get('searchValue');
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($query) use ($search) {
                            $query->where('f_name', 'like', "%{$search}%")
                                ->orWhere('l_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request?->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->when($request?->filled('type'), function ($query) use ($request) {
                $request->get('type') === 'custom'
                    ? $query->where('is_custom', 1)
                    : $query->where('is_custom', 0);
            })
            ->orderBy('sort_order')
            ->orderByDesc('id');

        $packages = $query->paginate(getWebConfig(name: 'pagination_limit'))->appends($request?->query() ?? []);
        $customers = User::query()
            ->select('id', 'f_name', 'l_name', 'email', 'phone')
            ->where('id', '!=', 0)
            ->where('email', '!=', 'walking@customer.com')
            ->orderBy('f_name')
            ->get();

        return view('admin-views.customer.purchase-package.list', compact('packages', 'customers'));
    }

    public function store(CustomerPurchasePackageRequest $request): RedirectResponse
    {
        CustomerPurchasePackage::query()->create($this->getPackageData($request, true));

        ToastMagic::success(translate('customer_purchase_package_added_successfully'));
        return redirect()->route('admin.customer.purchase-package.index');
    }

    public function edit(int|string $id): View|RedirectResponse
    {
        $package = CustomerPurchasePackage::query()->with('customer:id,f_name,l_name,email,phone')->find($id);

        if (!$package) {
            ToastMagic::error(translate('customer_purchase_package_not_found'));
            return redirect()->route('admin.customer.purchase-package.index');
        }

        $customers = User::query()
            ->select('id', 'f_name', 'l_name', 'email', 'phone')
            ->where('id', '!=', 0)
            ->where('email', '!=', 'walking@customer.com')
            ->orderBy('f_name')
            ->get();

        return view('admin-views.customer.purchase-package.edit', compact('package', 'customers'));
    }

    public function update(CustomerPurchasePackageRequest $request, int|string $id): RedirectResponse
    {
        $package = CustomerPurchasePackage::query()->find($id);

        if (!$package) {
            ToastMagic::error(translate('customer_purchase_package_not_found'));
            return redirect()->route('admin.customer.purchase-package.index');
        }

        $package->update($this->getPackageData($request, false));

        ToastMagic::success(translate('customer_purchase_package_updated_successfully'));
        return redirect()->route('admin.customer.purchase-package.index');
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $package = CustomerPurchasePackage::query()->find($request->get('id'));

        if (!$package) {
            return response()->json(['success' => 0, 'message' => translate('customer_purchase_package_not_found')], 404);
        }

        $package->update([
            'status' => $request->get('status', 0),
            'updated_by_admin_id' => auth('admin')->id(),
        ]);

        return response()->json(['success' => 1, 'message' => translate('status_updated_successfully')]);
    }

    public function destroy(Request $request, int|string $id): RedirectResponse
    {
        $package = CustomerPurchasePackage::query()->withCount('subscriptions')->find($id);

        if (!$package) {
            ToastMagic::error(translate('customer_purchase_package_not_found'));
            return redirect()->back();
        }

        if ($package->subscriptions_count > 0) {
            ToastMagic::error(translate('cannot_delete_package_assigned_to_customers'));
            return redirect()->back();
        }

        $package->delete();

        ToastMagic::success(translate('customer_purchase_package_deleted_successfully'));
        return redirect()->back();
    }

    public function offlinePaymentReviews(Request $request): RedirectResponse
    {
        return redirect()->route('admin.payment-reviews.index');
    }

    public function approveOfflinePayment(Request $request, int|string $id, CustomerActivationInvoiceService $activationInvoiceService): RedirectResponse
    {
        $invoice = CustomerActivationInvoice::query()
            ->where('payment_method', 'offline_payment')
            ->where('status', 'pending_offline_review')
            ->where('payment_status', 'unpaid')
            ->find($id);

        if (!$invoice) {
            ToastMagic::error(translate('offline_payment_review_not_found'));
            return redirect()->back();
        }

        $result = $activationInvoiceService->markInvoicePaidAndReleaseOrder($invoice, [
            'id' => 'offline-review-' . $invoice->id,
            'payment_method' => 'offline_payment',
            'transaction_id' => $request->get('payment_reference') ?: ('offline-review-' . $invoice->id),
            'payment_amount' => (float) $invoice->total_amount,
            'currency_code' => $invoice->currency_code,
        ]);

        if (($result['status'] ?? 0) != 1) {
            ToastMagic::error(translate($result['message'] ?? 'offline_payment_approval_failed'));
            return redirect()->back();
        }

        $metadata = $invoice->fresh()->metadata ?: [];
        $metadata['offline_payment_review'] = [
            'action' => 'approved',
            'reviewed_by_admin_id' => auth('admin')->id(),
            'reviewed_at' => now()->toDateTimeString(),
            'note' => $request->get('review_note'),
        ];
        $invoice->fresh()->update(['metadata' => $metadata]);

        ToastMagic::success(translate('offline_payment_approved_successfully'));
        return redirect()->back();
    }

    public function rejectOfflinePayment(Request $request, int|string $id): RedirectResponse
    {
        $invoice = CustomerActivationInvoice::query()
            ->where('payment_method', 'offline_payment')
            ->where('status', 'pending_offline_review')
            ->where('payment_status', 'unpaid')
            ->find($id);

        if (!$invoice) {
            ToastMagic::error(translate('offline_payment_review_not_found'));
            return redirect()->back();
        }

        $metadata = $invoice->metadata ?: [];
        $metadata['offline_payment_history'][] = [
            'info' => $metadata['offline_payment'] ?? null,
            'review' => [
                'action' => 'rejected',
                'reviewed_by_admin_id' => auth('admin')->id(),
                'reviewed_at' => now()->toDateTimeString(),
                'note' => $request->get('review_note'),
            ],
        ];
        unset($metadata['offline_payment']);

        $invoice->update([
            'status' => 'pending',
            'payment_method' => null,
            'payment_reference' => null,
            'metadata' => $metadata,
        ]);

        ToastMagic::success(translate('offline_payment_rejected_successfully'));
        return redirect()->back();
    }

    private function getPackageData(CustomerPurchasePackageRequest $request, bool $isNew): array
    {
        $isCustom = $request->filled('customer_id');

        $data = [
            'customer_id' => $isCustom ? $request->get('customer_id') : null,
            'name' => $request->get('name'),
            'description' => $request->get('description'),
            'package_price' => currencyConverter($request->get('package_price')),
            'purchase_limit' => currencyConverter($request->get('purchase_limit')),
            'is_custom' => $isCustom,
            'status' => $request->get('status', 1),
            'sort_order' => $request->get('sort_order', 0),
            'duration_unit' => $request->get('duration_unit') ?: null,
            'duration_value' => $request->get('duration_unit') === 'lifetime'
                ? null
                : ($request->filled('duration_value') ? (int) $request->get('duration_value') : null),
            'updated_by_admin_id' => auth('admin')->id(),
        ];

        if ($isNew) {
            $data['created_by_admin_id'] = auth('admin')->id();
        }

        return $data;
    }
}
