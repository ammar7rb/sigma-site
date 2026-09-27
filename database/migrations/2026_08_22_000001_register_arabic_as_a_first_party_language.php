<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('business_settings')) {
            return;
        }

        $languageSetting = DB::table('business_settings')->where('type', 'language')->first();
        if (!$languageSetting) {
            return;
        }

        $languages = json_decode($languageSetting->value ?? '[]', true);
        $languages = is_array($languages) ? $languages : [];

        if (!collect($languages)->contains(fn (array $language): bool => ($language['code'] ?? null) === 'ar')) {
            $nextId = collect($languages)->max(fn (array $language): int => (int) ($language['id'] ?? 0)) + 1;
            $languages[] = [
                'id' => $nextId,
                'name' => 'العربية',
                'code' => 'ar',
                'direction' => 'rtl',
                'status' => 1,
                'default' => false,
            ];

            DB::table('business_settings')
                ->where('id', $languageSetting->id)
                ->update(['value' => json_encode($languages, JSON_UNESCAPED_UNICODE)]);
        }

        $pncSetting = DB::table('business_settings')->where('type', 'pnc_language')->first();
        $codes = $pncSetting ? json_decode($pncSetting->value ?? '[]', true) : [];
        $codes = is_array($codes) ? $codes : [];
        if (!in_array('ar', $codes, true)) {
            $codes[] = 'ar';
            if ($pncSetting) {
                DB::table('business_settings')->where('id', $pncSetting->id)
                    ->update(['value' => json_encode($codes)]);
            } else {
                DB::table('business_settings')->insert(['type' => 'pnc_language', 'value' => json_encode($codes)]);
            }
        }
    }

    public function down(): void
    {
        // Preserve an administrator's enabled language and translations on rollback.
    }
};
