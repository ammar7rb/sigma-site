<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the existing settings rows, but present express delivery as one
        // Sigma option with a cutoff-based promise.
        DB::table('business_settings')
            ->where('type', 'customer_shipping_same_day_title')
            ->whereIn('value', ['Same Day Delivery', ''])
            ->update(['value' => 'Sigma Delivery']);

        DB::table('business_settings')
            ->where('type', 'customer_shipping_same_day_duration')
            ->whereIn('value', ['Same day', ''])
            ->update(['value' => 'Same day before 12:00 / next day after 12:00']);

        DB::table('business_settings')
            ->where('type', 'customer_shipping_next_day_duration')
            ->whereIn('value', ['Next day', ''])
            ->update(['value' => 'Next day after 12:00']);

        Cache::forget('cache_business_settings_table');
        app(\App\Services\CustomerThreeStepShippingService::class)->syncMethods();
    }

    public function down(): void
    {
        DB::table('business_settings')
            ->where('type', 'customer_shipping_same_day_title')
            ->where('value', 'Sigma Delivery')
            ->update(['value' => 'Same Day Delivery']);

        DB::table('business_settings')
            ->where('type', 'customer_shipping_same_day_duration')
            ->where('value', 'Same day before 12:00 / next day after 12:00')
            ->update(['value' => 'Same day']);

        DB::table('business_settings')
            ->where('type', 'customer_shipping_next_day_duration')
            ->where('value', 'Next day after 12:00')
            ->update(['value' => 'Next day']);

        Cache::forget('cache_business_settings_table');
    }
};
