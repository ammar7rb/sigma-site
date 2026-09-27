<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('length', 10, 2)->nullable()->after('minimum_order_qty');
            $table->decimal('width', 10, 2)->nullable()->after('length');
            $table->decimal('height', 10, 2)->nullable()->after('width');
            $table->string('dimension_unit', 10)->nullable()->after('height');
            $table->decimal('weight', 10, 2)->nullable()->after('dimension_unit');
            $table->string('weight_unit', 10)->nullable()->after('weight');
            $table->string('sale_unit_type', 16)->default('piece')->after('unit');
            $table->unsignedInteger('pieces_per_unit')->nullable()->after('sale_unit_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['length', 'width', 'height', 'dimension_unit', 'weight', 'weight_unit', 'sale_unit_type', 'pieces_per_unit']);
        });
    }
};
