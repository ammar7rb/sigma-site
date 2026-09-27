<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (config('egypt.governorates', []) as $governorate) {
            $origin = config('egypt.governorate_centers.' . $governorate);
            DB::table('egypt_shipping_zone_rates')->updateOrInsert(
                [
                    'country_code' => 'EG',
                    'governorate' => $governorate,
                    'district' => null,
                    'area' => null,
                    'street' => null,
                ],
                [
                    'dispatch_address' => 'مركز محافظة ' . $governorate,
                    'dispatch_latitude' => $origin['latitude'] ?? null,
                    'dispatch_longitude' => $origin['longitude'] ?? null,
                    'normal_cost' => 80,
                    'sigma_cost' => 240,
                    'included_distance_km' => 0,
                    'price_per_km' => 0,
                    'peak_multiplier' => 1,
                    'sigma_surcharge' => 0,
                    'normal_available' => true,
                    'sigma_available' => true,
                    'status' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('egypt_shipping_zone_rates')
            ->where('country_code', 'EG')
            ->whereNull('governorate')
            ->update(['status' => false, 'updated_at' => now()]);

        foreach ([
            'customer_three_step_shipping_status' => '1',
            'customer_shipping_normal_status' => '1',
            'customer_shipping_sigma_status' => '1',
            'customer_shipping_normal_title' => 'شحن عادي',
            'customer_shipping_sigma_title' => 'شحن سيجما',
            'shipping_promise_normal_mode' => 'fixed',
            'shipping_promise_normal_min_days' => '10',
            'shipping_promise_normal_max_days' => '10',
            'shipping_promise_sigma_mode' => 'fixed',
            'shipping_promise_sigma_min_days' => '6',
            'shipping_promise_sigma_max_days' => '6',
        ] as $type => $value) {
            DB::table('business_settings')->updateOrInsert(
                ['type' => $type],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Preserve rates because an administrator may have edited them after deployment.
    }
};
