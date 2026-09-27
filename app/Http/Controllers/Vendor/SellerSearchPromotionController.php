<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SellerProductPromotion;
use App\Services\SellerProductPromotionService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SellerSearchPromotionController extends Controller
{
    public function __construct(
        private readonly SellerProductPromotionService $promotionService,
    ) {}

    public function index(Request $request): View
    {
        $seller = auth('seller')->user();
        $products = Product::query()
            ->withoutGlobalScopes()
            ->with(['activeSearchPromotion'])
            ->where(['added_by' => 'seller', 'user_id' => $seller->id])
            ->where(['status' => 1, 'request_status' => 1])
            ->when($request->get('searchValue'), function ($query, $searchValue) {
                $query->where('name', 'like', "%{$searchValue}%");
            })
            ->latest('id')
            ->paginate(getWebConfig(name: 'pagination_limit') ?? 25)
            ->appends($request->all());

        return view('vendor-views.promotion.search', [
            'products' => $products,
            'summary' => $this->promotionService->getSearchSummary($seller),
            'history' => $seller->productPromotions()
                ->where('promotion_type', SellerProductPromotion::TYPE_SEARCH)
                ->with('product:id,name')
                ->latest('id')
                ->limit(10)
                ->get(),
            'searchValue' => $request->get('searchValue'),
        ]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $request->validate(['product_id' => 'required|integer']);
        $seller = auth('seller')->user();
        $product = Product::query()
            ->withoutGlobalScopes()
            ->where(['added_by' => 'seller', 'user_id' => $seller->id])
            ->find($request->get('product_id'));
        if (! $product) {
            ToastMagic::error(translate('seller_product_not_found'));

            return back();
        }

        try {
            $promotion = $this->promotionService->activateSearchPromotion($product, $seller);
            ToastMagic::success(
                translate('search_promotion_activated_until').' '.$promotion->expires_at
            );
        } catch (DomainException $exception) {
            ToastMagic::warning(translate($exception->getMessage()));
        }

        return back();
    }
}
