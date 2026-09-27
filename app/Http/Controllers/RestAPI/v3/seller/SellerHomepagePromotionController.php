<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SellerProductPromotion;
use App\Services\SellerProductPromotionService;
use App\Utils\Helpers;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SellerHomepagePromotionController extends Controller
{
    public function __construct(
        private readonly SellerProductPromotionService $promotionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $products = Product::query()
            ->withoutGlobalScopes()
            ->with(['activeHomepagePromotion', 'latestHomepagePromotion'])
            ->where(['added_by' => 'seller', 'user_id' => $seller->id])
            ->where(['status' => 1, 'request_status' => 1])
            ->latest('id')
            ->get()
            ->map(fn (Product $product) => $this->formatProduct($product));

        return response()->json([
            'summary' => $this->formatSummary($this->promotionService->getHomepageSummary($seller)),
            'products' => $products,
            'history' => $seller->productPromotions()
                ->where('promotion_type', SellerProductPromotion::TYPE_HOMEPAGE)
                ->with('product:id,name')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (SellerProductPromotion $promotion) => $this->formatPromotion($promotion)),
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['product_id' => 'required|integer']);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $seller = $request->seller;
        $product = Product::query()
            ->withoutGlobalScopes()
            ->where(['added_by' => 'seller', 'user_id' => $seller->id])
            ->find($request->get('product_id'));
        if (! $product) {
            return response()->json([
                'errors' => [[
                    'code' => 'seller_product_not_found',
                    'message' => translate('seller_product_not_found'),
                ]],
            ], 404);
        }

        try {
            $promotion = $this->promotionService->activateHomepagePromotion($product, $seller);

            return response()->json([
                'message' => translate('Advertising_request_sent_for_admin_review'),
                'promotion' => $this->formatPromotion($promotion),
                'summary' => $this->formatSummary($this->promotionService->getHomepageSummary($seller)),
            ]);
        } catch (DomainException $exception) {
            return response()->json([
                'errors' => [[
                    'code' => $exception->getMessage(),
                    'message' => translate($exception->getMessage()),
                ]],
            ], 403);
        }
    }

    private function formatSummary(array $summary): array
    {
        return [
            'insurance_satisfied' => $summary['insurance_satisfied'],
            'active_package' => $summary['active_subscription']?->package_name,
            'homepage_promotion_limit' => $summary['homepage_promotion_limit'],
            'used_homepage_promotion_limit' => $summary['used_homepage_promotion_limit'],
            'remaining_homepage_promotion_limit' => $summary['remaining_homepage_promotion_limit'],
            'homepage_promotion_duration_days' => $summary['homepage_promotion_duration_days'],
            'can_promote' => $summary['can_promote'],
        ];
    }

    private function formatProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'thumbnail_full_url' => $product->thumbnail_full_url,
            'is_homepage_promoted' => (bool) $product->activeHomepagePromotion,
            'has_pending_ad_request' => $product->latestHomepagePromotion?->approval_status === SellerProductPromotion::APPROVAL_PENDING,
            'homepage_promotion' => $product->latestHomepagePromotion
                ? $this->formatPromotion($product->latestHomepagePromotion)
                : null,
        ];
    }

    private function formatPromotion(SellerProductPromotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'product_id' => $promotion->product_id,
            'product_name' => $promotion->product?->name,
            'promotion_type' => $promotion->promotion_type,
            'duration_days' => $promotion->duration_days,
            'status' => $promotion->status,
            'approval_status' => $promotion->approval_status,
            'review_note' => $promotion->review_note,
            'placement_scope' => $promotion->placement_scope,
            'placement_key' => $promotion->placement_key,
            'requested_position' => $promotion->requested_position,
            'approved_position' => $promotion->approved_position,
            'badge_label' => data_get($promotion->metadata, 'badge_label'),
            'requested_at' => $promotion->requested_at,
            'reviewed_at' => $promotion->reviewed_at,
            'starts_at' => $promotion->starts_at,
            'expires_at' => $promotion->expires_at,
        ];
    }
}
