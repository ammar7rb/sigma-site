<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('seller_review_changes')->nullable()->after('seller_review_reason');
        });

        Schema::table('seller_product_review_decisions', function (Blueprint $table) {
            $table->json('changed_fields')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('seller_product_review_decisions', function (Blueprint $table) {
            $table->dropColumn('changed_fields');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('seller_review_changes');
        });
    }
};