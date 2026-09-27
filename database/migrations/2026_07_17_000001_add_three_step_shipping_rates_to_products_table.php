<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // These rates are admin-owned and represent one unit of each physical product.
            $table->decimal('same_day_shipping_cost', 40, 20)->nullable()->after('shipping_cost');
            $table->decimal('next_day_shipping_cost', 40, 20)->nullable()->after('same_day_shipping_cost');
            $table->decimal('normal_shipping_cost', 40, 20)->nullable()->after('next_day_shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'same_day_shipping_cost',
                'next_day_shipping_cost',
                'normal_shipping_cost',
            ]);
        });
    }
};
