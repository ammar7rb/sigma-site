<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_product_promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('seller_package_subscription_id')->index();
            $table->unsignedBigInteger('seller_product_entitlement_id')->nullable()->index();
            $table->string('promotion_type', 50)->index();
            $table->unsignedInteger('duration_days')->default(0);
            $table->integer('sort_order')->default(0)->index();
            $table->string('status', 50)->default('reserved')->index();
            $table->boolean('quota_restored')->default(false)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('quota_restored_at')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['promotion_type', 'status', 'expires_at'], 'seller_product_promotions_type_status_expiry_index');
            $table->index(['product_id', 'promotion_type', 'status'], 'seller_product_promotions_product_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_product_promotions');
    }
};
