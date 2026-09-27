<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            'customer_extra_credit_status' => '0',
            'customer_extra_credit_min_amount' => '50',
            'customer_extra_credit_step_amount' => '100',
            'customer_extra_credit_rate' => '10',
            'customer_extra_credit_max_amount' => '0',
            'customer_extra_credit_rounding_rule' => 'ceil_step',
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
    }

    public function down(): void
    {
        DB::table('business_settings')
            ->whereIn('type', [
                'customer_extra_credit_status',
                'customer_extra_credit_min_amount',
                'customer_extra_credit_step_amount',
                'customer_extra_credit_rate',
                'customer_extra_credit_max_amount',
                'customer_extra_credit_rounding_rule',
            ])
            ->delete();
    }
};
