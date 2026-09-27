<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('package_price', 40, 20)->default(0);
            $table->unsignedInteger('product_limit')->default(0);
            $table->unsignedInteger('product_duration_days')->default(0);
            $table->unsignedInteger('search_promotion_limit')->default(0);
            $table->unsignedInteger('search_promotion_duration_days')->default(0);
            $table->unsignedInteger('homepage_promotion_limit')->default(0);
            $table->unsignedInteger('homepage_promotion_duration_days')->default(0);
            $table->unsignedInteger('package_validity_days')->nullable();
            $table->boolean('status')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_packages');
    }
};
