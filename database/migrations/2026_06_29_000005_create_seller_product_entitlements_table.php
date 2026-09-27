<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_product_entitlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('seller_package_subscription_id')->index();
            $table->unsignedBigInteger('seller_package_id')->nullable()->index();
            $table->unsignedInteger('duration_days')->default(0);
            $table->string('status', 50)->default('reserved')->index();
            $table->boolean('quota_restored')->default(false)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('quota_restored_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status'], 'seller_product_entitlements_product_status_index');
            $table->index(['seller_id', 'status'], 'seller_product_entitlements_seller_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_product_entitlements');
    }
};
