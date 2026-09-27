<?php

namespace App\Services;

class SellerProductSpecificationService
{
    public function validate(array $input): array
    {
        $errors = [];
        $saleUnitType = ($input['sale_unit_type'] ?? 'piece') === 'box'
            ? 'package'
            : ($input['sale_unit_type'] ?? 'piece');
        if (!in_array($saleUnitType, ['piece', 'package'], true)) {
            $errors['sale_unit_type'] = 'seller_product_sale_unit_type_invalid';
        }
        if ($saleUnitType === 'package' && filter_var($input['pieces_per_unit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $errors['pieces_per_unit'] = 'seller_product_pieces_per_unit_required';
        }

        $dimensionValues = array_filter([
            $input['length'] ?? null,
            $input['width'] ?? null,
            $input['height'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        foreach ($dimensionValues as $value) {
            if (!is_numeric($value) || (float) $value <= 0) {
                $errors['dimensions'] = 'seller_product_dimension_value_invalid';
                break;
            }
        }
        if ($dimensionValues && !in_array($input['dimension_unit'] ?? null, ['cm', 'm'], true)) {
            $errors['dimension_unit'] = 'seller_product_dimension_unit_required';
        }

        if (($input['weight'] ?? null) !== null && ($input['weight'] ?? null) !== '') {
            if (!is_numeric($input['weight']) || (float) $input['weight'] <= 0) {
                $errors['weight'] = 'seller_product_weight_value_invalid';
            }
            if (!in_array($input['weight_unit'] ?? null, ['g', 'kg'], true)) {
                $errors['weight_unit'] = 'seller_product_weight_unit_required';
            }
        }

        return $errors;
    }

    public function normalize(array $input): array
    {
        $saleUnitType = ($input['sale_unit_type'] ?? null) === 'box'
            ? 'package'
            : ($input['sale_unit_type'] ?? 'piece');
        $saleUnitType = in_array($saleUnitType, ['piece', 'package'], true) ? $saleUnitType : 'piece';
        $hasDimensions = collect(['length', 'width', 'height'])->contains(fn ($key) => ($input[$key] ?? null) !== null && ($input[$key] ?? null) !== '');
        $hasWeight = ($input['weight'] ?? null) !== null && ($input['weight'] ?? null) !== '';

        return [
            'length' => $hasDimensions ? (float) ($input['length'] ?? 0) : null,
            'width' => $hasDimensions ? (float) ($input['width'] ?? 0) : null,
            'height' => $hasDimensions ? (float) ($input['height'] ?? 0) : null,
            'dimension_unit' => $hasDimensions ? ($input['dimension_unit'] ?? null) : null,
            'weight' => $hasWeight ? (float) $input['weight'] : null,
            'weight_unit' => $hasWeight ? ($input['weight_unit'] ?? null) : null,
            'sale_unit_type' => $saleUnitType,
            'pieces_per_unit' => $saleUnitType === 'package' ? (int) ($input['pieces_per_unit'] ?? 0) : null,
        ];
    }
}
