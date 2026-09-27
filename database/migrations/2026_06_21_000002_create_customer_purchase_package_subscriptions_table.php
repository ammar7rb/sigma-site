<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_purchase_package_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('customer_purchase_package_id')->nullable();
            $table->string('package_name')->nullable();
            $table->decimal('paid_package_price', 40, 20)->default(0);
            $table->decimal('package_purchase_limit', 40, 20)->default(0);
            $table->decimal('used_purchase_limit', 40, 20)->default(0);
            $table->decimal('extra_credit_limit', 40, 20)->default(0);
            $table->decimal('admin_adjustment_limit', 40, 20)->default(0);
            $table->decimal('available_purchase_limit', 40, 20)->default(0);
            $table->string('status', 50)->default('pending')->index();
            $table->string('payment_status', 50)->default('unpaid')->index();
            $table->string('payment_method', 191)->nullable();
            $table->string('payment_reference', 191)->nullable()->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('customer_purchase_package_id', 'customer_pkg_sub_package_index');
            $table->index(['customer_id', 'status'], 'customer_purchase_subscriptions_customer_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_purchase_package_subscriptions');
    }
};
