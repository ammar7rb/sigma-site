<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\BaseController;
use App\Models\Seller;
use App\Models\SellerPackageSubscription;
use App\Models\SellerProductEntitlement;
use App\Models\SellerProductPromotion;
use App\Services\SellerPackagePurchaseService;
use App\Services\SellerCommissionService;
use App\Services\SellerProductEntitlementService;
use App\Services\SellerProductPromotionService;
use App\Services\SellerQuotaAdjustmentService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class SellerQuotaControlController extends BaseController
{
    public function __construct(
        private readonly SellerQuotaAdjustmentService $adjustmentService,
        private readonly SellerPackagePurchaseService $packagePurchaseService,
        private readonly SellerProductEntitlementService $entitlementService,
        private readonly SellerProductPromotionService $promotionService,
        private readonly SellerCommissionService $commissionService,
    ) {}

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse|JsonResponse
    {
        $request ??= request();

        SellerPackageSubscription::query()
            ->where('status', SellerPackageSubscription::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => SellerPackageSubscription::STATUS_EXPIRED]);
        $this->promotionService->expireDuePromotions();

        $sellers = Seller::query()
            ->with([
                'shop:id,seller_id,name',
                'activeInsurance',
                'activePackageSubscription',
            ])
            ->withCount([
                'product',
                'productEntitlements as active_listings_count' => fn ($query) => $query
                    ->where('status', SellerProductEntitlement::STATUS_ACTIVE)
                    ->where(function ($query) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    }),
                'productPromotions as active_search_promotions_count' => fn ($query) => $query
                    ->where('promotion_type', SellerProductPromotion::TYPE_SEARCH)
                    ->where('status', SellerProductPromotion::STATUS_ACTIVE)
                    ->where(function ($query) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    }),
                'productPromotions as active_homepage_promotions_count' => fn ($query) => $query
                    ->where('promotion_type', SellerProductPromotion::TYPE_HOMEPAGE)
                    ->where('status', SellerProductPromotion::STATUS_ACTIVE)
                    ->where(function ($query) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    }),
            ])
            ->when($request->filled('searchValue'), function ($query) use ($request) {
                $search = $request->get('searchValue');
                $query->where(function ($query) use ($search) {
                    $query->where('f_name', 'like', "%{$search}%")
                        ->orWhere('l_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhereHas('shop', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->get('package_status') === 'active', fn ($query) => $query->whereHas('activePackageSubscription'))
            ->when($request->get('package_status') === 'missing', fn ($query) => $query->whereDoesntHave('activePackageSubscription'))
            ->latest('id')
            ->paginate(getWebConfig(name: 'pagination_limit') ?? 25)
            ->appends($request->query());

        return view('admin-views.vendor.quota-control.index', [
            'sellers' => $sellers,
            'defaultCommission' => $this->commissionService->getSummary(null)['default_rate'],
        ]);
    }

    public function show(Seller $seller): View
    {
        $this->entitlementService->expireDueEntitlements($seller->id);
        $this->promotionService->expireDuePromotions($seller->id);
        $packageSummary = $this->packagePurchaseService->getSummary($seller);

        return view('admin-views.vendor.quota-control.show', [
            'seller' => $seller->load(['shop', 'activeInsurance']),
            'packageSummary' => $packageSummary,
            'subscription' => $packageSummary['active_subscription'],
            'insuranceHistory' => $seller->insurances()->latest('id')->limit(10)->get(),
            'subscriptions' => $seller->packageSubscriptions()->latest('id')->limit(10)->get(),
            'entitlements' => $seller->productEntitlements()->with('product:id,name')->latest('id')->limit(20)->get(),
            'promotions' => $seller->productPromotions()->with('product:id,name')->latest('id')->limit(30)->get(),
            'transactions' => $seller->packageTransactions()->with('createdByAdmin:id,name')->latest('id')->limit(30)->get(),
            'requestToken' => (string) Str::uuid(),
            'commissionSummary' => $this->commissionService->getSummary($seller),
        ]);
    }

    public function adjust(Request $request, Seller $seller): RedirectResponse
    {
        $validated = $request->validate([
            'quota_type' => 'required|in:product,search_promotion,homepage_promotion,coupon',
            'operation' => 'required|in:add,deduct',
            'amount' => 'required|integer|min:1|max:1000000',
            'reason' => 'required|string|max:1000',
            'request_token' => 'required|uuid',
        ]);

        try {
            $result = $this->adjustmentService->adjustQuota(
                seller: $seller,
                quotaType: $validated['quota_type'],
                operation: $validated['operation'],
                amount: (int) $validated['amount'],
                adminId: auth('admin')->id(),
                reason: $validated['reason'],
                requestToken: $validated['request_token'],
            );
            ToastMagic::success(translate($result['applied'] ? 'seller_quota_adjusted_successfully' : 'seller_quota_adjustment_was_already_applied'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }

    public function updatePromotion(Request $request, SellerProductPromotion $promotion): RedirectResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:update_order,cancel',
            'sort_order' => 'required|integer|min:-1000000|max:1000000',
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $this->adjustmentService->updatePromotion(
                promotion: $promotion,
                action: $validated['action'],
                sortOrder: (int) $validated['sort_order'],
                adminId: auth('admin')->id(),
                reason: $validated['reason'],
            );
            ToastMagic::success(translate('seller_promotion_updated_successfully'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }
}
