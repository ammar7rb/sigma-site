<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class User
 *
 * @property int $id
 * @property string $name
 * @property string $f_name
 * @property string $l_name
 * @property string $phone
 * @property string $image
 * @property string $email
 * @property $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property $created_at
 * @property $updated_at
 * @property string $street_address
 * @property string $country
 * @property string $city
 * @property string $zip
 * @property string $house_no
 * @property string $apartment_no
 * @property string|null $cm_firebase_token
 * @property bool $is_active
 * @property string|null $payment_card_last_four
 * @property string|null $payment_card_brand
 * @property string|null $payment_card_fawry_token
 * @property string|null $login_medium
 * @property string|null $social_id
 * @property bool $is_phone_verified
 * @property string|null $temporary_token
 * @property bool $is_email_verified
 * @property float $wallet_balance
 * @property float $loyalty_point
 * @property int $login_hit_count
 * @property bool $is_temp_blocked
 * @property $temp_block_time
 * @property string|null $referral_code
 * @property int $referred_by
 *
 * @package App\Models
 */
class CustomerUpdateSettingsRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'add_fund_bonus' => 'nullable|numeric|max:100|min:0',
            'loyalty_point_exchange_rate' => 'nullable|numeric|min:1',
            'ref_earning_exchange_rate' => 'nullable|numeric|min:0',
            'maximum_add_fund_amount' => 'nullable|numeric|min:0',
            'minimum_add_fund_amount' => 'nullable|numeric|min:1',
            'item_purchase_point' => 'nullable|numeric|min:0',
            'minimum_transfer_point' => 'nullable|numeric|min:0',
            'customer_extra_credit_min_amount' => 'nullable|numeric|min:0.01',
            'customer_extra_credit_step_amount' => 'nullable|numeric|min:0.01',
            'customer_extra_credit_rate' => 'nullable|numeric|min:0|max:100',
            'customer_extra_credit_max_amount' => 'nullable|numeric|min:0',
            'customer_extra_credit_rounding_rule' => 'nullable|in:ceil_step,exact_shortage',
            'customer_monthly_insurance_amount' => 'nullable|numeric|min:0',
            'customer_monthly_insurance_first_discount_type' => 'nullable|in:none,fixed,percentage,free',
            'customer_monthly_insurance_first_discount_value' => 'nullable|numeric|min:0',
            'customer_monthly_insurance_period_days' => 'nullable|integer|min:1',
            'customer_activation_hold_message' => 'nullable|string|max:1000',
            'customer_shipping_sigma_title' => 'nullable|string|max:191',
            'customer_shipping_sigma_cost' => 'nullable|numeric|min:0',
            'customer_shipping_normal_title' => 'nullable|string|max:191',
            'customer_shipping_normal_cost' => 'nullable|numeric|min:0',
            'shipping_promise_sigma_mode' => 'nullable|in:fixed,range',
            'shipping_promise_sigma_min_days' => 'nullable|integer|min:1|max:60',
            'shipping_promise_sigma_max_days' => 'nullable|integer|min:1|max:60|gte:shipping_promise_sigma_min_days',
            'shipping_promise_normal_mode' => 'nullable|in:fixed,range',
            'shipping_promise_normal_min_days' => 'nullable|integer|min:1|max:60',
            'shipping_promise_normal_max_days' => 'nullable|integer|min:1|max:60|gte:shipping_promise_normal_min_days',
            'commerce_working_days' => 'nullable|array|min:1',
            'commerce_working_days.*' => 'integer|between:0,6',
            'commerce_holidays' => 'nullable|string|max:5000',
            'customer_product_shipping_quantity_threshold' => 'nullable|integer|min:0',
            'customer_product_shipping_extra_charge_type' => 'nullable|in:percent,fixed',
            'customer_product_shipping_extra_charge_value' => 'nullable|numeric|min:0',
            'customer_manual_transfer_method_name' => 'nullable|string|max:191',
        ];

        if ($this->request->get('ref_earning_discount_status') == 1) {
            $rules = array_merge($rules, [
                'discount_amount' => 'required|numeric|min:0',
                'discount_type' => 'required|in:percentage,flat',
                'validity' => 'required|numeric|min:1',
                'validity_type' => 'required|in:day,week,month',
            ]);
        }

        return $rules;
    }


    public function messages(): array
    {
        return [
            'add_fund_bonus.numeric' => translate('The_add_fund_bonus_must_be_a_numeric_value'),
            'add_fund_bonus.max' => translate('The_add_fund_bonus_cannot_exceed_100'),
            'add_fund_bonus.min' => translate('The_add_fund_bonus_must_be_at_least_0'),
            'loyalty_point_exchange_rate.numeric' => translate('The_loyalty_point_exchange_rate_must_be_a_numeric_value'),
            'loyalty_point_exchange_rate.min' => translate('The_loyalty_point_exchange_rate_must_be_at_least_1'),
            'ref_earning_exchange_rate.numeric' => translate('The_referral_earning_exchange_rate_must_be_a_numeric_value'),
            'ref_earning_exchange_rate.min' => translate('The_referral_earning_exchange_rate_must_be_at_least_0'),
            'maximum_add_fund_amount.numeric' => translate('The_maximum_add_fund_amount_must_be_a_numeric_value'),
            'maximum_add_fund_amount.min' => translate('The_maximum_add_fund_amount_must_be_at_least_0'),
            'minimum_add_fund_amount.numeric' => translate('The_minimum_add_fund_amount_must_be_a_numeric_value'),
            'minimum_add_fund_amount.min' => translate('The_minimum_add_fund_amount_must_be_at_least_1'),
            'item_purchase_point.numeric' => translate('The_item_purchase_point_must_be_a_numeric_value'),
            'item_purchase_point.min' => translate('The_item_purchase_point_must_be_at_least_0'),
            'minimum_transfer_point.numeric' => translate('The_minimum_transfer_point_must_be_a_numeric_value'),
            'minimum_transfer_point.min' => translate('The_minimum_transfer_point_must_be_at_least_0'),
            'customer_extra_credit_min_amount.numeric' => translate('The_extra_credit_minimum_amount_must_be_a_numeric_value'),
            'customer_extra_credit_min_amount.min' => translate('The_extra_credit_minimum_amount_must_be_at_least_0.01'),
            'customer_extra_credit_step_amount.numeric' => translate('The_extra_credit_step_amount_must_be_a_numeric_value'),
            'customer_extra_credit_step_amount.min' => translate('The_extra_credit_step_amount_must_be_at_least_0.01'),
            'customer_extra_credit_rate.numeric' => translate('The_extra_credit_rate_must_be_a_numeric_value'),
            'customer_extra_credit_rate.min' => translate('The_extra_credit_rate_must_be_at_least_0'),
            'customer_extra_credit_rate.max' => translate('The_extra_credit_rate_cannot_exceed_100'),
            'customer_extra_credit_max_amount.numeric' => translate('The_extra_credit_maximum_amount_must_be_a_numeric_value'),
            'customer_extra_credit_max_amount.min' => translate('The_extra_credit_maximum_amount_must_be_at_least_0'),
            'customer_extra_credit_rounding_rule.in' => translate('The_extra_credit_rounding_rule_is_invalid'),
            'discount_amount.required' => translate('The_discount_amount_field_is_required_when_referral_earning_discount_is_enabled'),
            'discount_amount.numeric' => translate('The_discount_amount_must_be_a_number'),
            'discount_amount.min' => translate('The_discount_amount_must_be_at_least_0'),
            'discount_type.required' => translate('The_discount_type_field_is_required_when_referral_earning_discount_is_enabled'),
            'discount_type.in' => translate('The_discount_type_must_be_either_percentage_or_amount'),
            'validity.required' => translate('The_validity_field_is_required_when_referral_earning_discount_is_enabled'),
            'validity.numeric' => translate('The_validity_must_be_a_number'),
            'validity.min' => translate('The_validity_must_be_at_least_1'),
            'validity_type.required' => translate('The_validity_type_field_is_required_when_referral_earning_discount_is_enabled'),
            'validity_type.in' => translate('The_validity_type_must_be_day_week_or_month'),
        ];
    }

}
