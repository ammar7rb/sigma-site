<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_fulfillment_mode', 32)->default('pending')->index();
            $table->string('shipping_assignment_status', 32)->default('pending')->index();
            $table->decimal('shipping_customer_cost', 24, 3)->nullable();
            $table->decimal('shipping_seller_cost', 24, 3)->nullable();
            $table->decimal('shipping_seller_entitlement', 24, 3)->nullable();
            $table->dateTime('shipping_decided_at')->nullable();
            $table->unsignedBigInteger('shipping_decided_by')->nullable()->index();
            $table->text('shipping_decision_note')->nullable();
        });

        Schema::create('order_shipping_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('previous_mode', 32)->nullable();
            $table->string('mode', 32);
            $table->string('assignment_status', 32)->default('assigned');
            $table->decimal('customer_cost', 24, 3)->nullable();
            $table->decimal('seller_cost', 24, 3)->nullable();
            $table->decimal('seller_entitlement', 24, 3)->nullable();
            $table->string('service_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->text('instructions')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipping_decisions');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['shipping_fulfillment_mode']);
            $table->dropIndex(['shipping_assignment_status']);
            $table->dropIndex(['shipping_decided_by']);
            $table->dropColumn([
                'shipping_fulfillment_mode',
                'shipping_assignment_status',
                'shipping_customer_cost',
                'shipping_seller_cost',
                'shipping_seller_entitlement',
                'shipping_decided_at',
                'shipping_decided_by',
                'shipping_decision_note',
            ]);
        });
    }
};
