<?php

namespace App\Services;

use App\Models\Category;

class SellerProductCategoryService
{
    public function validate(array $input): array
    {
        $categoryId = $input['category_id'] ?? null;
        if (!$categoryId || !Category::withoutGlobalScopes()->where(['id' => $categoryId, 'position' => 0, 'is_active' => 1])->exists()) {
            return ['category_id' => 'seller_product_category_invalid'];
        }

        $subCategoryId = $input['sub_category_id'] ?? null;
        if ($subCategoryId && !Category::withoutGlobalScopes()->where(['id' => $subCategoryId, 'parent_id' => $categoryId, 'position' => 1, 'is_active' => 1])->exists()) {
            return ['sub_category_id' => 'seller_product_sub_category_invalid'];
        }

        $subSubCategoryId = $input['sub_sub_category_id'] ?? null;
        if ($subSubCategoryId && (!$subCategoryId || !Category::withoutGlobalScopes()->where(['id' => $subSubCategoryId, 'parent_id' => $subCategoryId, 'position' => 2, 'is_active' => 1])->exists())) {
            return ['sub_sub_category_id' => 'seller_product_sub_sub_category_invalid'];
        }

        return [];
    }
}
