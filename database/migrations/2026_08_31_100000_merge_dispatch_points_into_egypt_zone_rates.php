<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('egypt_shipping_zone_rates', function (Blueprint $table): void {
            $table->decimal('dispatch_latitude', 10, 7)->nullable()->after('street');
            $table->decimal('dispatch_longitude', 10, 7)->nullable()->after('dispatch_latitude');
        });

        Schema::table('shipping_addresses', function (Blueprint $table): void {
            $table->foreignId('shipping_zone_rate_id')->nullable()->after('dispatch_point_id')
                ->constrained('egypt_shipping_zone_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_addresses', function (Blueprint $table): void {
            $table->dropForeign(['shipping_zone_rate_id']);
            $table->dropColumn('shipping_zone_rate_id');
        });

        Schema::table('egypt_shipping_zone_rates', function (Blueprint $table): void {
            $table->dropColumn(['dispatch_latitude', 'dispatch_longitude']);
        });
    }
};
