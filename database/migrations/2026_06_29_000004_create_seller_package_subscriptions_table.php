<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_package_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('seller_package_id')->nullable()->index();
            $table->string('package_name')->nullable();
            $table->decimal('paid_package_price', 40, 20)->default(0);
            $table->unsignedInteger('product_limit')->default(0);
            $table->unsignedInteger('used_product_limit')->default(0);
            $table->integer('product_adjustment_limit')->default(0);
            $table->unsignedInteger('product_duration_days')->default(0);
            $table->unsignedInteger('search_promotion_limit')->default(0);
            $table->unsignedInteger('used_search_promotion_limit')->default(0);
            $table->integer('search_promotion_adjustment_limit')->default(0);
            $table->unsignedInteger('search_promotion_duration_days')->default(0);
            $table->unsignedInteger('homepage_promotion_limit')->default(0);
            $table->unsignedInteger('used_homepage_promotion_limit')->default(0);
            $table->integer('homepage_promotion_adjustment_limit')->default(0);
            $table->unsignedInteger('homepage_promotion_duration_days')->default(0);
            $table->unsignedInteger('package_validity_days')->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->string('payment_status', 50)->default('unpaid')->index();
            $table->string('payment_method', 191)->nullable();
            $table->string('payment_reference', 191)->nullable()->index();
            $table->char('payment_request_id', 36)->nullable()->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'seller_package_subscriptions_seller_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_package_subscriptions');
    }
};
