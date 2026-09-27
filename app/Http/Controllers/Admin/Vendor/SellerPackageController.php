<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\SellerPackageRequest;
use App\Models\SellerPackage;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class SellerPackageController extends BaseController
{
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse|JsonResponse
    {
        $request ??= request();
        $packages = SellerPackage::query()
            ->withCount([
                'subscriptions',
                'subscriptions as active_subscriptions_count' => fn ($query) => $query->where('status', 'active'),
            ])
            ->when($request->filled('searchValue'), function ($query) use ($request) {
                $search = $request->get('searchValue');
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(getWebConfig(name: 'pagination_limit'))
            ->appends($request->query());

        return view('admin-views.vendor.package.index', compact('packages'));
    }

    public function store(SellerPackageRequest $request): RedirectResponse
    {
        SellerPackage::query()->create($this->getPackageData($request, true));

        ToastMagic::success(translate('seller_package_added_successfully'));

        return redirect()->route('admin.vendors.packages.index');
    }

    public function edit(int|string $id): View|RedirectResponse
    {
        $package = SellerPackage::query()->withCount('subscriptions')->find($id);
        if (! $package) {
            ToastMagic::error(translate('seller_package_not_found'));

            return redirect()->route('admin.vendors.packages.index');
        }

        return view('admin-views.vendor.package.edit', compact('package'));
    }

    public function update(SellerPackageRequest $request, int|string $id): RedirectResponse
    {
        $package = SellerPackage::query()->find($id);
        if (! $package) {
            ToastMagic::error(translate('seller_package_not_found'));

            return redirect()->route('admin.vendors.packages.index');
        }

        $package->update($this->getPackageData($request));

        ToastMagic::success(translate('seller_package_updated_successfully'));

        return redirect()->route('admin.vendors.packages.index');
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer',
            'status' => 'nullable|boolean',
        ]);
        $package = SellerPackage::query()->find($request->get('id'));
        if (! $package) {
            return response()->json(['success' => 0, 'message' => translate('seller_package_not_found')], 404);
        }

        $package->update([
            'status' => $request->boolean('status'),
            'updated_by_admin_id' => auth('admin')->id(),
        ]);

        return response()->json(['success' => 1, 'message' => translate('status_updated_successfully')]);
    }

    public function destroy(int|string $id): RedirectResponse
    {
        $package = SellerPackage::query()->withCount('subscriptions')->find($id);
        if (! $package) {
            ToastMagic::error(translate('seller_package_not_found'));

            return back();
        }

        if ($package->subscriptions_count > 0) {
            ToastMagic::error(translate('seller_package_with_subscription_cannot_be_deleted_disable_it_instead'));

            return back();
        }

        $package->delete();
        ToastMagic::success(translate('seller_package_deleted_successfully'));

        return back();
    }

    private function getPackageData(SellerPackageRequest $request, bool $isNew = false): array
    {
        $searchPromotionLimit = (int) $request->get('search_promotion_limit', 0);
        $homepagePromotionLimit = (int) $request->get('homepage_promotion_limit', 0);
        $data = [
            'name' => $request->get('name'),
            'slug' => $this->generateUniqueSlug($request->get('name'), $request->route('id')),
            'description' => $request->get('description'),
            'package_price' => currencyConverter(amount: $request->get('package_price')),
            'product_limit' => (int) $request->get('product_limit'),
            'product_duration_days' => (int) $request->get('product_duration_days'),
            'search_promotion_limit' => $searchPromotionLimit,
            'search_promotion_duration_days' => $searchPromotionLimit > 0
                ? (int) $request->get('search_promotion_duration_days')
                : 0,
            'homepage_promotion_limit' => $homepagePromotionLimit,
            'homepage_promotion_duration_days' => $homepagePromotionLimit > 0
                ? (int) $request->get('homepage_promotion_duration_days')
                : 0,
            // Coupon creation is a package entitlement; zero keeps the feature locked for this package.
            'coupon_limit' => (int) $request->get('coupon_limit', 0),
            'package_validity_days' => $request->filled('package_validity_days')
                ? (int) $request->get('package_validity_days')
                : null,
            'status' => $request->boolean('status'),
            'sort_order' => (int) $request->get('sort_order', 0),
            'updated_by_admin_id' => auth('admin')->id(),
        ];

        if ($isNew) {
            $data['created_by_admin_id'] = auth('admin')->id();
        }

        return $data;
    }

    private function generateUniqueSlug(string $name, int|string|null $ignoreId = null): string
    {
        $baseSlug = Str::slug($name) ?: 'seller-package';
        $slug = $baseSlug;
        $suffix = 2;

        while (SellerPackage::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }
}
