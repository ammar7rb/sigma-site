<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shipping_addresses', 'building_number')) {
            Schema::table('shipping_addresses', function (Blueprint $table): void {
                $table->string('building_number', 100)->nullable()->after('street');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipping_addresses', 'building_number')) {
            Schema::table('shipping_addresses', function (Blueprint $table): void {
                $table->dropColumn('building_number');
            });
        }
    }
};
