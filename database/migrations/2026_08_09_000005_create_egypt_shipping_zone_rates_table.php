<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egypt_shipping_zone_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('country_code', 2)->default('EG');
            $table->string('governorate')->nullable();
            $table->string('district')->nullable();
            $table->string('area')->nullable();
            $table->decimal('normal_cost', 40, 20)->nullable();
            $table->decimal('sigma_cost', 40, 20)->nullable();
            $table->boolean('normal_available')->default(true);
            $table->boolean('sigma_available')->default(true);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index(['country_code', 'governorate', 'district', 'area'], 'eg_zone_location_idx');
            $table->index(['status', 'normal_available', 'sigma_available'], 'eg_zone_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egypt_shipping_zone_rates');
    }
};
