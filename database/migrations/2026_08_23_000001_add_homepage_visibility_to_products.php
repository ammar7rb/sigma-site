<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'is_homepage_visible')) {
            Schema::table('products', function (Blueprint $table) {
                // Existing products preserve their current storefront behavior.
                $table->boolean('is_homepage_visible')->default(true)->after('featured')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'is_homepage_visible')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('is_homepage_visible');
            });
        }
    }
};
