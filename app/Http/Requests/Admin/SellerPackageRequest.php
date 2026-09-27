<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SellerPackageRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $packageId = $this->route('id') ?? $this->input('id');

        return [
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('seller_packages', 'name')->ignore($packageId),
            ],
            'description' => 'nullable|string|max:2000',
            'package_price' => 'required|numeric|min:0.01|max:999999999999.99',
            'product_limit' => 'nullable|integer|min:0|max:1000000',
            'product_duration_days' => 'nullable|integer|min:0|max:3650',
            'search_promotion_limit' => 'required|integer|min:0|max:1000000',
            'search_promotion_duration_days' => 'exclude_if:search_promotion_limit,0|required|integer|min:1|max:3650',
            'homepage_promotion_limit' => 'required|integer|min:0|max:1000000',
            'homepage_promotion_duration_days' => 'exclude_if:homepage_promotion_limit,0|required|integer|min:1|max:3650',
            'coupon_limit' => 'required|integer|min:0|max:1000000',
            'package_validity_days' => 'nullable|integer|min:1|max:3650',
            'duration_unit' => 'nullable|in:days,months,years,lifetime',
            'duration_value' => 'nullable|integer|min:1|max:100000',
            'sort_order' => 'nullable|integer|min:0|max:1000000',
            'search_priority' => 'nullable|integer|min:1|max:1000000',
            'cancellation_effect' => 'nullable|in:end_of_period,immediate',
            'status' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('the_name_field_is_required'),
            'name.unique' => translate('seller_package_name_already_exists'),
            'package_price.min' => translate('package_price_must_be_greater_than_zero'),
            'search_promotion_duration_days.required' => translate('search_promotion_duration_is_required_when_the_quota_is_greater_than_zero'),
            'homepage_promotion_duration_days.required' => translate('homepage_promotion_duration_is_required_when_the_quota_is_greater_than_zero'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $benefits = (int) $this->input('search_promotion_limit', 0)
                + (int) $this->input('homepage_promotion_limit', 0)
                + (int) $this->input('coupon_limit', 0);
            if ($benefits <= 0) {
                $validator->errors()->add('search_promotion_limit', translate('advertising_package_must_include_at_least_one_promotional_benefit'));
            }
        });
    }
}
