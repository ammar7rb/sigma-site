<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $settings = [
            'billing_input_by_customer' => '0',
            'customer_three_step_shipping_status' => '1',
            'customer_shipping_normal_status' => '1',
            'customer_shipping_sigma_status' => '1',
            'customer_shipping_normal_title' => 'شحن عادي',
            'customer_shipping_sigma_title' => 'شحن سيجما',
            'shipping_promise_normal_mode' => 'range',
            'shipping_promise_normal_min_days' => '7',
            'shipping_promise_normal_max_days' => '14',
            'shipping_promise_sigma_mode' => 'range',
            'shipping_promise_sigma_min_days' => '2',
            'shipping_promise_sigma_max_days' => '7',
        ];
        foreach ($settings as $type => $value) {
            DB::table('business_settings')->updateOrInsert(
                ['type' => $type],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        DB::table('egypt_shipping_zone_rates')->where('status', 1)->update([
            'normal_available' => 1,
            'sigma_available' => 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('business_settings')->where('type', 'billing_input_by_customer')->update(['value' => '1', 'updated_at' => now()]);
    }
};
