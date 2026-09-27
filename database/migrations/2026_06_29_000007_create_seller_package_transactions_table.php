<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_package_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('seller_package_subscription_id')->nullable()->index();
            $table->unsignedBigInteger('seller_package_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('seller_product_entitlement_id')->nullable()->index();
            $table->unsignedBigInteger('seller_product_promotion_id')->nullable()->index();
            $table->char('payment_request_id', 36)->nullable()->index();
            $table->uuid('transaction_id')->unique();
            $table->string('transaction_type', 100)->index();
            $table->string('quota_type', 50)->nullable()->index();
            $table->integer('credit')->default(0);
            $table->integer('debit')->default(0);
            $table->integer('balance_after')->default(0);
            $table->decimal('paid_amount', 40, 20)->default(0);
            $table->string('reference', 191)->nullable()->index();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'transaction_type'], 'seller_package_transactions_seller_type_index');
            $table->index(['seller_id', 'quota_type'], 'seller_package_transactions_seller_quota_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_package_transactions');
    }
};
