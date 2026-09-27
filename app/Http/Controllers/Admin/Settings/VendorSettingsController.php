<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\VendorSettingsRequest;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VendorSettingsController extends BaseController
{

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $sales_commission = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'sales_commission']);
        if (!isset($sales_commission)) {
            $this->businessSettingRepo->add(data: ['type' => 'sales_commission', 'value' => 0]);
        }

        $seller_registration = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'seller_registration']);
        if (!isset($seller_registration)) {
            $this->businessSettingRepo->add(data: ['type' => 'seller_registration', 'value' => 1]);
        }
        return view('admin-views.business-settings.seller-settings');
    }

    public function update(VendorSettingsRequest $request): RedirectResponse
    {
        $holidayValues = preg_split('/[,\r\n\s]+/', trim((string) $request->get('seller_settlement_holidays', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $holidays = [];
        foreach ($holidayValues as $holiday) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $holiday);
            if (! $date || $date->format('Y-m-d') !== $holiday) {
                return back()->withErrors(['seller_settlement_holidays' => translate('settlement_holidays_must_use_yyyy_mm_dd')])->withInput();
            }
            $holidays[] = $holiday;
        }

        $this->businessSettingRepo->updateOrInsert(type: 'seller_pos', value: $request->get('seller_pos', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'seller_registration', value: $request->get('seller_registration', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'minimum_order_amount_by_seller', value: $request->get('minimum_order_amount_by_seller', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_review_reply_status', value: $request->get('vendor_review_reply_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_can_edit_order', value: $request->get('vendor_can_edit_order', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_forgot_password_method', value: $request->get('vendor_forgot_password_method', 'phone'));
        $this->businessSettingRepo->updateOrInsert(type: 'seller_insurance_status', value: $request->get('seller_insurance_status', 0));
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_insurance_amount',
            value: currencyConverter(amount: $request->get('seller_insurance_amount', 0))
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_insurance_repayment_after_forfeiture',
            value: $request->get('seller_insurance_repayment_after_forfeiture', 0)
        );
        $this->businessSettingRepo->updateOrInsert(type: 'seller_order_insurance_status', value: $request->get('seller_order_insurance_status', 0));
        $sellerInsuranceCalculationType = $request->get('seller_order_insurance_calculation_type', 'percentage');
        $sellerInsuranceCalculationValue = $request->get('seller_order_insurance_calculation_value', $request->get('seller_order_insurance_percentage', 0));
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_order_insurance_percentage',
            value: $sellerInsuranceCalculationType === 'percentage' ? $sellerInsuranceCalculationValue : 0
        );
        $this->businessSettingRepo->updateOrInsert(type: 'seller_order_insurance_calculation_type', value: $sellerInsuranceCalculationType);
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_order_insurance_calculation_value',
            value: $sellerInsuranceCalculationType === 'fixed'
                ? currencyConverter(amount: $sellerInsuranceCalculationValue) : $sellerInsuranceCalculationValue
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_order_insurance_pending_days',
            value: $request->get('seller_order_insurance_pending_days', 3)
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_order_insurance_reuse_after_days',
            value: $request->get('seller_order_insurance_reuse_after_days', 90)
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_order_insurance_expiry_action',
            value: $request->get('seller_order_insurance_expiry_action', 'admin_review')
        );
        if (\Illuminate\Support\Facades\Schema::hasTable('insurance_rules')) {
            \App\Models\InsuranceRule::query()->updateOrCreate(
                ['subject_type' => 'seller', 'code' => 'seller-default'],
                [
                    'name' => 'Seller default order insurance', 'rule_type' => 'default', 'priority' => 1000,
                    'is_active' => (bool) $request->get('seller_order_insurance_status', 0),
                    'minimum_order_amount' => 0, 'maximum_order_amount' => null,
                    'minimum_account_age_days' => 0, 'maximum_account_age_days' => null,
                    'calculation_type' => $sellerInsuranceCalculationType,
                    'calculation_value' => $sellerInsuranceCalculationType === 'fixed'
                        ? currencyConverter(amount: $sellerInsuranceCalculationValue) : $sellerInsuranceCalculationValue,
                ]
            );
        }
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_settlement_hold_days',
            value: $request->integer('seller_settlement_hold_days', 45)
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_settlement_working_days',
            value: json_encode(array_values(array_unique($request->input('seller_settlement_working_days', [0, 1, 2, 3, 4]))))
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_settlement_holidays',
            value: json_encode(array_values(array_unique($holidays)))
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_settlement_pre_due_alert_days',
            value: $request->integer('seller_settlement_pre_due_alert_days', 5)
        );
        $this->businessSettingRepo->updateOrInsert(
            type: 'seller_settlement_overdue_escalation_days',
            value: $request->integer('seller_settlement_overdue_escalation_days', 2)
        );
        ToastMagic::success(translate('Updated_successfully'));
        return redirect()->back();
    }

}
