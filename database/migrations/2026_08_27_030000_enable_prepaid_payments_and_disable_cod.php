<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (['cash_on_delivery' => 0, 'digital_payment' => 1, 'offline_payment' => 1] as $type => $status) {
            DB::table('business_settings')->updateOrInsert(
                ['type' => $type],
                ['value' => json_encode(['status' => $status]), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('business_settings')->where('type', 'cash_on_delivery')
            ->update(['value' => json_encode(['status' => 1]), 'updated_at' => now()]);
    }
};
