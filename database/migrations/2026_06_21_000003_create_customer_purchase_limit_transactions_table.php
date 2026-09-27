<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_purchase_limit_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('customer_purchase_package_subscription_id')->nullable();
            $table->unsignedBigInteger('customer_purchase_package_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->char('payment_request_id', 36)->nullable()->index();
            $table->uuid('transaction_id')->unique();
            $table->string('transaction_type', 100)->index();
            $table->decimal('credit', 40, 20)->default(0);
            $table->decimal('debit', 40, 20)->default(0);
            $table->decimal('balance_after', 40, 20)->default(0);
            $table->decimal('product_amount', 40, 20)->default(0);
            $table->decimal('paid_amount', 40, 20)->default(0);
            $table->string('reference', 191)->nullable()->index();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('customer_purchase_package_subscription_id', 'customer_limit_tx_subscription_index');
            $table->index('customer_purchase_package_id', 'customer_limit_tx_package_index');
            $table->index(['customer_id', 'transaction_type'], 'customer_purchase_limit_customer_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_purchase_limit_transactions');
    }
};
