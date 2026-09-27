<?php

namespace App\Http\Controllers\Admin\Product;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\SeoMetaInfoRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Exports\CategoryListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\CategoryAddRequest;
use App\Http\Requests\Admin\CategoryUpdateRequest;
use App\Services\CategoryService;
use App\Services\ProductService;
use App\Services\SeoMetaInfoService;
use App\Traits\PaginatorTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CategoryController extends BaseController
{
    use PaginatorTrait;
    use VatTaxManagement;

    public function __construct(
        private readonly CategoryRepositoryInterface    $categoryRepo,
        private readonly ProductRepositoryInterface     $productRepo,
        private readonly ProductService                 $productService,
        private readonly SeoMetaInfoService             $seoMetaInfoService,
        private readonly CategoryService                $categoryService,
        private readonly SeoMetaInfoRepositoryInterface $seoMetaInfoRepo,
        private readonly TranslationRepositoryInterface $translationRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $taxData = $this->getTaxSystemType();
        $categoryWiseTax = $taxData['categoryWiseTax'];
        $taxVats = $taxData['taxVats'];

        $categories = $this->categoryRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: $request['searchValue'],
            filters: ['position' => 0],
            relations: $categoryWiseTax ? ['taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }] : [],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );

        $categoriesWithTrans = $this->categoryRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: $request->get('searchValue'),
            filters: ['position' => 0],
            relations: $categoryWiseTax ? ['taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }, 'translations', 'seo'] : ['translations', 'seo'],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );

        $languages = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $languages[0];
        return view('admin-views.category.view', [
            'categories' => $categories,
            'categoriesWithTrans' => $categoriesWithTrans,
            'languages' => $languages,
            'defaultLanguage' => $defaultLanguage,
            'taxVats' => $taxVats,
            'categoryWiseTax' => $categoryWiseTax,
            'categoryPosition' => 0,
        ]);
    }

    public function getUpdateView(Request $request): View|RedirectResponse
    {
        $category = $this->categoryRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['translations']);
        $languages = getWebConfig(name: 'pnc_language') ?? null;
        $defaultLanguage = $languages[0];
        return view('admin-views.category.category-edit', [
            'category' => $category,
            'languages' => $languages,
            'defaultLanguage' => $defaultLanguage,
        ]);
    }

    public function add(CategoryAddRequest $request): RedirectResponse|JsonResponse
    {
        $dataArray = $this->categoryService->getAddData(request: $request);
        $savedCategory = $this->categoryRepo->add(data: $dataArray);
        $this->translationRepo->add(request: $request, model: 'App\Models\Category', id: $savedCategory->id);

        $request->merge(['meta_index' => 'index']);
        $request->merge(['meta_no_follow' => 0]);

        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $savedCategory?->seo, type: 'App\Models\Category', modelId: $savedCategory->id, action: 'add');
        $this->seoMetaInfoRepo->add(data: $seoMetaData);

        if ($savedCategory['position'] == 0) {
            $this->getAddTaxData(
                taxableType: \App\Models\Category::class,
                taxableId: $savedCategory->id,
                taxIds: $request['tax_ids'] ?? []
            );
        }

        updateSetupGuideCacheKey(key: 'category_setup', panel: 'admin');

        if ($request->ajax()) {
            return response()->json([
                'status' => 'success',
                'message' => translate('category_added_successfully'),
                'redirect_url' => route('admin.category.view'),
            ]);
        }

        ToastMagic::success(translate('category_added_successfully'));
        return back();
    }

    public function update(CategoryUpdateRequest $request): RedirectResponse
    {
        $category = $this->categoryRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['seo']);

        if ($category['position'] == 1 && $category['parent_id'] != $request['parent_id']) {
            $this->productRepo->updateByParams(
                ['sub_category_id' => $category['id']],
                [
                    'category_id' => $request['parent_id'],
                    'category_ids' => DB::raw("JSON_SET(CAST(category_ids AS JSON), '$[0].id', '{$request['parent_id']}')")
                ]
            );
        }

        $dataArray = $this->categoryService->getUpdateData(request: $request, data: $category);
        $this->categoryRepo->update(id: $request['id'], data: $dataArray);
        $this->translationRepo->update(request: $request, model: 'App\Models\Category', id: $request['id']);

        $request->merge(['meta_index' => 'index']);
        $request->merge(['meta_no_follow' => 0]);

        $seoMetaData = $this->seoMetaInfoService->getModelSEOData(request: $request, seoMetaInfo: $category?->seo, type: 'App\Models\Category', modelId: $category->id, action: 'update');
        $this->seoMetaInfoRepo->updateOrInsert(params: ['seoable_type' => 'App\Models\Category', 'seoable_id' => $category['id']], data: $seoMetaData);

        $taxVatIds = $category?->taxVats?->pluck('tax_id')->toArray() ?? [];
        $this->getUpdateTaxData(
            taxableType: \App\Models\Category::class,
            taxableId: $category['id'],
            taxIds: $request['tax_ids'] ?? [],
            oldTaxIds: $taxVatIds
        );

        updateSetupGuideCacheKey(key: 'category_setup', panel: 'admin');

        if ($category['position'] == 1) {
            ToastMagic::success(translate('Sub_Category_updated_successfully'));
        } elseif ($category['position'] == 2) {
            ToastMagic::success(translate('Sub_Sub_Category_updated_successfully'));
        } else {
            ToastMagic::success(translate('category_updated_successfully'));
        }
        return back();
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $data = [
            'home_status' => $request->get('home_status', 0),
        ];
        $this->categoryRepo->update(id: $request['id'], data: $data);
        updateSetupGuideCacheKey(key: 'category_setup', panel: 'admin');
        return response()->json(['success' => 1, 'message' => translate('Status_updated_successfully!')], 200);
    }

    public function delete(Request $request): RedirectResponse
    {
        $category = $this->categoryRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['childes.childes']);
        if (!$category) {
            ToastMagic::error(translate('category_not_found'));
            return back();
        }
        $hasChildren = $category->childes->isNotEmpty();
        $hasProducts = \App\Models\Product::withoutGlobalScopes()->where('category_id', $category->id)->exists();
        if ($hasChildren || $hasProducts) {
            ToastMagic::error(translate('used_categories_cannot_be_deleted_deactivate_them_instead'));
            return back();
        }
        $this->categoryService->deleteImages(data: $category);
        $this->categoryRepo->delete(params: ['id' => $request['id']]);
        ToastMagic::success(translate('deleted_successfully'));
        return redirect()->back();
    }

    public function medicalIndex(): View
    {
        $categories = \App\Models\Category::withoutGlobalScopes()
            ->where('position', 0)
            ->with(['childes' => function ($query) {
                $query->withCount('product')->with(['childes' => function ($query) {
                    $query->withCount('product');
                }]);
            }])
            ->withCount('product')
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        return view('admin-views.category.medical-management', compact('categories'));
    }

    public function updateMedicalStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate(['id' => 'required|integer', 'is_active' => 'required|boolean']);
        $category = \App\Models\Category::withoutGlobalScopes()->find($validated['id']);
        if (!$category) {
            return back()->withErrors(['id' => translate('category_not_found')]);
        }
        $category->update(['is_active' => (bool) $validated['is_active']]);
        updateSetupGuideCacheKey(key: 'category_setup', panel: 'admin');
        return back()->with('success', translate('status_updated_successfully'));
    }

    public function updateMedicalPriority(Request $request): RedirectResponse
    {
        $validated = $request->validate(['id' => 'required|integer', 'priority' => 'required|integer|min:0|max:999999']);
        $category = \App\Models\Category::withoutGlobalScopes()->find($validated['id']);
        if (!$category) {
            return back()->withErrors(['id' => translate('category_not_found')]);
        }
        $category->update(['priority' => $validated['priority']]);
        updateSetupGuideCacheKey(key: 'category_setup', panel: 'admin');
        return back()->with('success', translate('priority_updated_successfully'));
    }

    public function getExportList(Request $request): BinaryFileResponse
    {
        $taxData = $this->getTaxSystemType();
        $categoryWiseTax = $taxData['categoryWiseTax'];
        $taxVats = $taxData['taxVats'];

        $categories = $this->categoryRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: $request->get('searchValue'),
            filters: ['position' => 0],
            relations: $categoryWiseTax ? ['taxVats' => function ($query) {
                return $query->with(['tax'])->wherehas('tax', function ($query) {
                    return $query->where('is_active', 1);
                });
            }] : [],
            dataLimit: 'all');
        $active = $categories->where('home_status', 1)->count();
        $inactive = $categories->where('home_status', 0)->count();
        return Excel::download(new CategoryListExport([
            'categories' => $categories,
            'title' => 'category',
            'search' => $request['searchValue'],
            'active' => $active,
            'inactive' => $inactive,
            'category_wise_tax' => $categoryWiseTax,
        ]), 'category-list.xlsx'
        );
    }
}
