<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Make EGP the platform default while retaining stored numeric amounts. */
    public function up(): void
    {
        if (!Schema::hasTable('currencies') || !Schema::hasTable('business_settings')) {
            return;
        }

        $currency = DB::table('currencies')->whereIn('code', ['EGP', 'egp'])->orderBy('id')->first();
        if ($currency) {
            DB::table('currencies')->where('id', $currency->id)->update([
                'name' => 'Egyptian Pound', 'symbol' => 'ج.م', 'code' => 'EGP',
                'exchange_rate' => 1, 'status' => 1, 'updated_at' => now(),
            ]);
            $currencyId = $currency->id;
        } else {
            $currencyId = DB::table('currencies')->insertGetId([
                'name' => 'Egyptian Pound', 'symbol' => 'ج.م', 'code' => 'EGP',
                'exchange_rate' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('business_settings')->updateOrInsert(
            ['type' => 'system_default_currency'],
            ['value' => (string) $currencyId, 'updated_at' => now()]
        );
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'currency_symbol_position'],
            ['value' => 'right', 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('currencies') || !Schema::hasTable('business_settings')) {
            return;
        }
        $usdId = DB::table('currencies')->where('code', 'USD')->value('id');
        if ($usdId) {
            DB::table('business_settings')->where('type', 'system_default_currency')->update([
                'value' => (string) $usdId, 'updated_at' => now(),
            ]);
        }
    }
};
