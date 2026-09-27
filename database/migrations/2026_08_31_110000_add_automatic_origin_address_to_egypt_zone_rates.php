<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('egypt_shipping_zone_rates', function (Blueprint $table): void {
            $table->string('dispatch_address')->nullable()->after('street');
        });
    }

    public function down(): void
    {
        Schema::table('egypt_shipping_zone_rates', function (Blueprint $table): void {
            $table->dropColumn('dispatch_address');
        });
    }
};
