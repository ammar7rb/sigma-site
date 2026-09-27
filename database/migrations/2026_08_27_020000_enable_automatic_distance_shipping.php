<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('egypt_shipping_zone_rates')->updateOrInsert(
            [
                'country_code' => 'EG',
                'governorate' => null,
                'district' => null,
                'area' => null,
                'street' => null,
            ],
            [
                'normal_cost' => 25,
                'sigma_cost' => null,
                'included_distance_km' => 5,
                'price_per_km' => 3,
                'peak_multiplier' => 1,
                'sigma_surcharge' => 0,
                'normal_available' => true,
                'sigma_available' => false,
                'status' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        foreach ([
            'customer_three_step_shipping_status' => '1',
            'customer_shipping_normal_status' => '1',
            'customer_shipping_sigma_status' => '0',
            'customer_shipping_normal_title' => 'Automatic distance delivery',
            'customer_shipping_normal_cost' => '25',
        ] as $type => $value) {
            DB::table('business_settings')->updateOrInsert(
                ['type' => $type],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('egypt_shipping_zone_rates')
            ->where('country_code', 'EG')
            ->whereNull('governorate')
            ->whereNull('district')
            ->whereNull('area')
            ->whereNull('street')
            ->delete();
    }
};
