<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            'customer_monthly_insurance_status' => '0',
            'customer_monthly_insurance_amount' => '0',
            'customer_monthly_insurance_first_discount_type' => 'none',
            'customer_monthly_insurance_first_discount_value' => '0',
            'customer_monthly_insurance_period_days' => '30',
            'customer_activation_hold_message' => 'Payment received successfully. Your order is pending until the monthly insurance and recommended package invoice is paid.',
            'customer_three_step_shipping_status' => '0',
            'customer_shipping_same_day_status' => '1',
            'customer_shipping_same_day_title' => 'Same Day Delivery',
            'customer_shipping_same_day_duration' => 'Same day',
            'customer_shipping_same_day_cost' => '0',
            'customer_shipping_same_day_cutoff' => '12:00',
            'customer_shipping_next_day_status' => '1',
            'customer_shipping_next_day_title' => 'Next Day Delivery',
            'customer_shipping_next_day_duration' => 'Next day',
            'customer_shipping_next_day_cost' => '0',
            'customer_shipping_normal_status' => '1',
            'customer_shipping_normal_title' => 'Normal Delivery',
            'customer_shipping_normal_duration' => '3 days',
            'customer_shipping_normal_cost' => '0',
            'customer_manual_transfer_method_name' => 'Manual Transfer / Auto Payment Form',
        ];

        foreach ($settings as $type => $value) {
            DB::table('business_settings')->updateOrInsert(
                ['type' => $type],
                [
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('offline_payment_methods')) {
            DB::table('offline_payment_methods')->updateOrInsert(
                ['method_name' => 'Manual Transfer / Auto Payment Form'],
                [
                    'method_fields' => json_encode([
                        ['input_name' => 'wallet_or_instapay_account', 'input_data' => 'Set wallet or InstaPay account from admin panel'],
                        ['input_name' => 'instructions', 'input_data' => 'Transfer the amount then submit sender number and payment screenshot.'],
                    ]),
                    'method_informations' => json_encode([
                        ['customer_input' => 'sender_wallet_or_phone', 'customer_placeholder' => 'Sender wallet / phone number', 'is_required' => 1],
                        ['customer_input' => 'sender_name', 'customer_placeholder' => 'Sender name', 'is_required' => 0],
                        ['customer_input' => 'payment_screenshot', 'customer_placeholder' => 'Payment screenshot', 'is_required' => 1],
                    ]),
                    'status' => 0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('business_settings')
            ->whereIn('type', [
                'customer_monthly_insurance_status',
                'customer_monthly_insurance_amount',
                'customer_monthly_insurance_first_discount_type',
                'customer_monthly_insurance_first_discount_value',
                'customer_monthly_insurance_period_days',
                'customer_activation_hold_message',
                'customer_three_step_shipping_status',
                'customer_shipping_same_day_status',
                'customer_shipping_same_day_title',
                'customer_shipping_same_day_duration',
                'customer_shipping_same_day_cost',
                'customer_shipping_same_day_cutoff',
                'customer_shipping_next_day_status',
                'customer_shipping_next_day_title',
                'customer_shipping_next_day_duration',
                'customer_shipping_next_day_cost',
                'customer_shipping_normal_status',
                'customer_shipping_normal_title',
                'customer_shipping_normal_duration',
                'customer_shipping_normal_cost',
                'customer_manual_transfer_method_name',
            ])
            ->delete();
    }
};
