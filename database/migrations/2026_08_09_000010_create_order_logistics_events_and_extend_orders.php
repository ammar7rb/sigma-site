<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_operational_status', 32)->default('pending_assignment')->index()->after('shipping_assignment_status');
            $table->string('shipping_responsible_party', 32)->nullable()->after('shipping_fulfillment_mode');
            $table->string('return_responsible_party', 32)->nullable()->index()->after('shipping_responsible_party');
            $table->decimal('return_shipping_cost', 24, 3)->nullable()->after('shipping_seller_entitlement');
            $table->string('return_tracking_number')->nullable()->after('third_party_delivery_tracking_id');
            $table->text('return_reason')->nullable()->after('return_tracking_number');
            $table->dateTime('return_started_at')->nullable()->after('return_reason');
            $table->dateTime('return_completed_at')->nullable()->after('return_started_at');
            $table->string('customer_delivery_confirmation_status', 32)->default('not_applicable')->index()->after('return_completed_at');
            $table->dateTime('customer_delivery_confirmation_due_at')->nullable()->after('customer_delivery_confirmation_status');
            $table->dateTime('customer_delivery_confirmed_at')->nullable()->after('customer_delivery_confirmation_due_at');
        });

        Schema::create('order_logistics_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('event_type', 40)->index();
            $table->string('shipping_status', 32)->nullable()->index();
            $table->string('responsible_party', 32)->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->decimal('customer_shipping_cost', 24, 3)->nullable();
            $table->decimal('seller_shipping_cost', 24, 3)->nullable();
            $table->decimal('return_shipping_cost', 24, 3)->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_logistics_events');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['shipping_operational_status']);
            $table->dropIndex(['return_responsible_party']);
            $table->dropIndex(['customer_delivery_confirmation_status']);
            $table->dropColumn([
                'shipping_operational_status', 'shipping_responsible_party', 'return_responsible_party',
                'return_shipping_cost', 'return_tracking_number', 'return_reason', 'return_started_at',
                'return_completed_at', 'customer_delivery_confirmation_status',
                'customer_delivery_confirmation_due_at', 'customer_delivery_confirmed_at',
            ]);
        });
    }
};
