<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            'seller_insurance_status' => '0',
            'seller_insurance_amount' => '0',
            'seller_insurance_repayment_after_forfeiture' => '1',
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
                'seller_insurance_status',
                'seller_insurance_amount',
                'seller_insurance_repayment_after_forfeiture',
            ])
            ->delete();
    }
};
