<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VendorSettingsRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_forgot_password_method' => 'nullable|in:email,phone',
            'seller_insurance_status' => 'nullable|in:1',
            'seller_insurance_amount' => 'exclude_unless:seller_insurance_status,1|required|numeric|min:0.01|max:999999999999.99',
            'seller_insurance_repayment_after_forfeiture' => 'nullable|in:1',
            'seller_order_insurance_status' => 'nullable|in:1',
            'seller_order_insurance_calculation_type' => 'exclude_unless:seller_order_insurance_status,1|required|in:percentage,fixed',
            'seller_order_insurance_calculation_value' => 'exclude_unless:seller_order_insurance_status,1|required|numeric|min:0.01|max:999999999999.99',
            'seller_order_insurance_percentage' => 'nullable|numeric|min:0|max:100',
            'seller_order_insurance_pending_days' => 'exclude_unless:seller_order_insurance_status,1|required|integer|min:1|max:90',
            'seller_order_insurance_reuse_after_days' => 'exclude_unless:seller_order_insurance_status,1|required|integer|min:1|max:365',
            'seller_order_insurance_expiry_action' => 'nullable|in:admin_review,suspend',
            'seller_settlement_hold_days' => 'nullable|integer|min:0|max:45',
            'seller_settlement_working_days' => 'nullable|array|min:1',
            'seller_settlement_working_days.*' => 'integer|between:0,6',
            'seller_settlement_holidays' => 'nullable|string|max:2000',
            'seller_settlement_pre_due_alert_days' => 'nullable|integer|min:1|max:30',
            'seller_settlement_overdue_escalation_days' => 'nullable|integer|min:1|max:30',
        ];
    }

    public function messages(): array
    {
        return [
            'seller_insurance_amount.required' => translate('seller_insurance_amount_is_required_when_insurance_is_enabled'),
            'seller_insurance_amount.min' => translate('seller_insurance_amount_must_be_greater_than_zero'),
            'seller_order_insurance_calculation_value.required' => translate('seller_order_insurance_calculation_value_is_required'),
            'seller_order_insurance_pending_days.required' => translate('seller_order_insurance_pending_days_is_required'),
            'seller_order_insurance_reuse_after_days.required' => translate('seller_order_insurance_reuse_after_days_is_required'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = $this->input('seller_order_insurance_calculation_type', 'percentage');
        $value = $this->input('seller_order_insurance_calculation_value', $this->input('seller_order_insurance_percentage', 0));
        $this->merge([
            'seller_order_insurance_calculation_type' => $type,
            'seller_order_insurance_calculation_value' => $value,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('seller_order_insurance_calculation_type') === 'percentage'
                && (float) $this->input('seller_order_insurance_calculation_value', 0) > 100) {
                $validator->errors()->add('seller_order_insurance_calculation_value', translate('percentage_cannot_exceed_100'));
            }
        });
    }

}
