<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_addresses', function (Blueprint $table): void {
            $table->string('district')->nullable()->after('city');
            $table->string('area')->nullable()->after('district');
            $table->string('street')->nullable()->after('area');
            $table->string('landmark')->nullable()->after('street');
            $table->foreignId('dispatch_point_id')->nullable()->after('longitude')
                ->constrained('egypt_shipping_dispatch_points')->nullOnDelete();
            $table->decimal('distance_km', 12, 3)->nullable()->after('dispatch_point_id');
            $table->string('geocode_status')->nullable()->after('distance_km');
            $table->string('geocoded_address')->nullable()->after('geocode_status');
            $table->timestamp('geocoded_at')->nullable()->after('geocoded_address');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_addresses', function (Blueprint $table): void {
            $table->dropForeign(['dispatch_point_id']);
            $table->dropColumn([
                'district', 'area', 'street', 'landmark', 'dispatch_point_id',
                'distance_km', 'geocode_status', 'geocoded_address', 'geocoded_at',
            ]);
        });
    }
};
