<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sellers', 'registration_reference')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->uuid('registration_reference')->nullable()->unique()->after('phone_verified_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sellers', 'registration_reference')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->dropUnique('sellers_registration_reference_unique');
                $table->dropColumn('registration_reference');
            });
        }
    }
};
