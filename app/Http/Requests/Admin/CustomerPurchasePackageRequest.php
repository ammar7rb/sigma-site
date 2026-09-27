<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerPurchasePackageRequest extends FormRequest
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
                Rule::unique('customer_purchase_packages', 'name')->ignore($packageId),
            ],
            'description' => 'nullable|string|max:1000',
            'package_price' => 'required|numeric|min:0.01',
            'purchase_limit' => 'required|numeric|min:0.01',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
            'customer_id' => 'nullable|exists:users,id',
            'duration_unit' => 'nullable|in:days,months,years,lifetime',
            'duration_value' => 'nullable|integer|min:1|max:100000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('the_name_field_is_required'),
            'name.unique' => translate('package_name_already_exists'),
            'package_price.required' => translate('package_price_is_required'),
            'package_price.numeric' => translate('package_price_must_be_a_numeric_value'),
            'package_price.min' => translate('package_price_must_be_at_least_0.01'),
            'purchase_limit.required' => translate('purchase_limit_is_required'),
            'purchase_limit.numeric' => translate('purchase_limit_must_be_a_numeric_value'),
            'purchase_limit.min' => translate('purchase_limit_must_be_at_least_0.01'),
            'customer_id.exists' => translate('selected_customer_not_found'),
        ];
    }
}
