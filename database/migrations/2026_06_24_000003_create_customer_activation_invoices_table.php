<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_activation_invoices')) {
            return;
        }

        Schema::create('customer_activation_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 100)->unique();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('order_group_id', 191)->nullable()->index();
            $table->unsignedBigInteger('customer_purchase_package_id')->nullable();
            $table->unsignedBigInteger('customer_purchase_package_subscription_id')->nullable();
            $table->char('payment_request_id', 36)->nullable()->index();
            $table->string('package_name', 191)->nullable();
            $table->decimal('package_price', 40, 20)->default(0);
            $table->decimal('package_purchase_limit', 40, 20)->default(0);
            $table->decimal('insurance_original_amount', 40, 20)->default(0);
            $table->decimal('insurance_discount_amount', 40, 20)->default(0);
            $table->string('insurance_discount_type', 50)->nullable();
            $table->decimal('insurance_amount', 40, 20)->default(0);
            $table->decimal('total_amount', 40, 20)->default(0);
            $table->decimal('paid_amount', 40, 20)->default(0);
            $table->string('currency_code', 20)->nullable();
            $table->string('payment_method', 191)->nullable();
            $table->string('payment_reference', 191)->nullable()->index();
            $table->string('payment_status', 50)->default('unpaid')->index();
            $table->string('status', 50)->default('pending')->index();
            $table->timestamp('insurance_period_start')->nullable();
            $table->timestamp('insurance_period_end')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('customer_purchase_package_id', 'customer_activation_pkg_id_index');
            $table->index('customer_purchase_package_subscription_id', 'customer_activation_sub_id_index');
            $table->index(['customer_id', 'status'], 'customer_activation_customer_status_index');
            $table->index(['order_group_id', 'status'], 'customer_activation_group_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activation_invoices');
    }
};
