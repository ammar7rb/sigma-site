<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Traits\CacheManagerTrait;
use App\Utils\Helpers;
use App\Services\HomepageSectionBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    use CacheManagerTrait;

    public function __construct(private readonly HomepageSectionBuilderService $homepageBuilder)
    {
    }

    public function getBannerList(Request $request): JsonResponse
    {
        $banners = collect($this->cacheBannerTable());
        $theme = theme_root_path();
        if (in_array($theme, HomepageSectionBuilderService::THEMES, true)) {
            $layout = $this->homepageBuilder->layout($theme);
            $sectionMap = [
                'Main Banner' => 'main_banner',
                'Main Section Banner' => 'main_section_banner',
                'Footer Banner' => 'footer_banners',
            ];
            $managed = collect();
            foreach ($sectionMap as $bannerType => $sectionKey) {
                $managed = $managed->concat($this->homepageBuilder->resolveBanners(
                    $theme,
                    $sectionKey,
                    $banners->where('banner_type', $bannerType),
                    $layout
                ));
            }
            $banners = $managed->concat($banners->whereNotIn('banner_type', array_keys($sectionMap)))->unique('id')->values();
        }
        $productIds = [];
        $shopIds = [];
        $brandIds = [];
        $categoryIds = [];
        $bannerData = [];
        foreach ($banners as $banner) {
            if ($banner['resource_type'] == 'product' && !in_array($banner['resource_id'], $productIds)) {
                $productIds[] = $banner['resource_id'];
                $product = Product::find($banner['resource_id']);
                $banner['product'] = Helpers::product_data_formatting($product);
            }
            if ($banner['resource_type'] == 'shop' && !in_array($banner['resource_id'], $shopIds)) {
                $shopIds[] = $banner['resource_id'];
                $banner['shop'] = Shop::where('id', $banner['resource_id'])->first();
            }
            if ($banner['resource_type'] == 'brand' && !in_array($banner['resource_id'], $brandIds)) {
                $brandIds[] = $banner['resource_id'];
                $banner['brand'] = Brand::where('id', $banner['resource_id'])->first();
            }
            if ($banner['resource_type'] == 'category' && !in_array($banner['resource_id'], $categoryIds)) {
                $categoryIds[] = $banner['resource_id'];
                $banner['category'] = Category::where('id', $banner['resource_id'])->first();
            }
            $bannerData[] = $banner;
        }

        return response()->json($bannerData, 200);

    }

    public function getHomepageLayout(): JsonResponse
    {
        $theme = theme_root_path();
        return response()->json($this->homepageBuilder->layout(
            in_array($theme, HomepageSectionBuilderService::THEMES, true) ? $theme : 'default'
        ));
    }
}
