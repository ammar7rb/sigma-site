<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Keep the per-seller shipping calculation that was active when the order was created.
            $table->unsignedInteger('shipping_quantity')->nullable()->after('shipping_cost');
            $table->decimal('shipping_product_subtotal', 40, 20)->nullable()->after('shipping_quantity');
            $table->decimal('shipping_quantity_surcharge', 40, 20)->nullable()->after('shipping_product_subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_quantity',
                'shipping_product_subtotal',
                'shipping_quantity_surcharge',
            ]);
        });
    }
};
