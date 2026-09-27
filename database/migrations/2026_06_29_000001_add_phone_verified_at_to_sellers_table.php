<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sellers', 'phone_verified_at')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sellers', 'phone_verified_at')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->dropColumn('phone_verified_at');
            });
        }
    }
};
