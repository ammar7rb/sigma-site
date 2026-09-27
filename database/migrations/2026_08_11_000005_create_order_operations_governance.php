<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('operational_blocked_by', 32)->nullable()->index()->after('order_status');
            $table->text('operational_block_reason')->nullable()->after('operational_blocked_by');
            $table->dateTime('operational_status_updated_at')->nullable()->index()->after('operational_block_reason');
            $table->unsignedInteger('seller_queue_priority')->default(1000)->index()->after('operational_status_updated_at');
            $table->unsignedInteger('seller_queue_position')->nullable()->index()->after('seller_queue_priority');
            $table->dateTime('seller_queue_override_at')->nullable()->after('seller_queue_position');
            $table->unsignedBigInteger('seller_queue_override_by')->nullable()->index()->after('seller_queue_override_at');
            $table->text('seller_queue_override_reason')->nullable()->after('seller_queue_override_by');
            $table->dateTime('customer_delivery_confirmation_expired_at')->nullable()->after('customer_delivery_confirmed_at');
            $table->string('shipment_reference', 64)->nullable()->unique()->after('third_party_delivery_tracking_id');
            $table->json('pickup_address_snapshot')->nullable()->after('shipment_reference');
            $table->json('customer_address_snapshot')->nullable()->after('pickup_address_snapshot');
            $table->json('shipping_price_snapshot')->nullable()->after('customer_address_snapshot');
        });

        Schema::table('order_logistics_events', function (Blueprint $table): void {
            $table->string('previous_order_status', 32)->nullable()->after('event_type');
            $table->string('order_status', 32)->nullable()->index()->after('previous_order_status');
            $table->string('blocked_by', 32)->nullable()->after('order_status');
            $table->string('reason_code', 64)->nullable()->after('blocked_by');
            $table->string('event_key', 191)->nullable()->unique()->after('reason_code');
        });

        Schema::table('egypt_shipping_zone_rates', function (Blueprint $table): void {
            $table->string('street')->nullable()->after('area');
            $table->decimal('included_distance_km', 12, 3)->default(0)->after('sigma_cost');
            $table->decimal('price_per_km', 24, 3)->default(0)->after('included_distance_km');
            $table->decimal('peak_multiplier', 8, 3)->default(1)->after('price_per_km');
            $table->decimal('sigma_surcharge', 24, 3)->default(0)->after('peak_multiplier');
        });

        Schema::create('order_operational_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('seller_id')->nullable()->index();
            $table->string('alert_type', 48)->index();
            $table->string('level', 24)->default('warning')->index();
            $table->string('order_status', 32)->nullable();
            $table->dateTime('due_at')->nullable()->index();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_settlement_disputes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_settlement_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('seller_id')->index();
            $table->string('reference_type', 80);
            $table->unsignedBigInteger('reference_id');
            $table->decimal('amount', 40, 20);
            $table->string('status', 24)->default('held')->index();
            $table->text('reason');
            $table->unsignedBigInteger('resolved_by_admin_id')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['reference_type', 'reference_id'], 'settlement_dispute_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlement_disputes');
        Schema::dropIfExists('order_operational_alerts');

        Schema::table('egypt_shipping_zone_rates', function (Blueprint $table): void {
            $table->dropColumn(['street', 'included_distance_km', 'price_per_km', 'peak_multiplier', 'sigma_surcharge']);
        });

        Schema::table('order_logistics_events', function (Blueprint $table): void {
            $table->dropIndex(['order_status']);
            $table->dropUnique(['event_key']);
            $table->dropColumn(['previous_order_status', 'order_status', 'blocked_by', 'reason_code', 'event_key']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['operational_blocked_by']);
            $table->dropIndex(['operational_status_updated_at']);
            $table->dropIndex(['seller_queue_priority']);
            $table->dropIndex(['seller_queue_position']);
            $table->dropIndex(['seller_queue_override_by']);
            $table->dropUnique(['shipment_reference']);
            $table->dropColumn([
                'operational_blocked_by', 'operational_block_reason', 'operational_status_updated_at',
                'seller_queue_priority', 'seller_queue_position', 'seller_queue_override_at',
                'seller_queue_override_by', 'seller_queue_override_reason',
                'customer_delivery_confirmation_expired_at', 'shipment_reference',
                'pickup_address_snapshot', 'customer_address_snapshot', 'shipping_price_snapshot',
            ]);
        });
    }
};
