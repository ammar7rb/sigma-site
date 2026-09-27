<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_admin_gate_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('action', 40)->index();
            $table->string('status_before', 60)->nullable();
            $table->string('status_after', 60)->nullable();
            $table->unsignedBigInteger('seller_order_insurance_id')->nullable()->index();
            $table->unsignedBigInteger('shipping_decision_id')->nullable()->index();
            $table->string('seller_insurance_source', 40)->nullable();
            $table->string('seller_insurance_calculation_type', 30)->nullable();
            $table->decimal('seller_insurance_calculation_value', 24, 3)->nullable();
            $table->decimal('seller_insurance_amount', 24, 3)->nullable();
            $table->text('override_reason')->nullable();
            $table->text('note')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('order_risk_investigations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('opened_by_admin_id')->nullable()->index();
            $table->unsignedBigInteger('resolved_by_admin_id')->nullable()->index();
            $table->string('status', 30)->default('open')->index();
            $table->string('risk_level', 20)->default('medium')->index();
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->boolean('customer_insurance_frozen')->default(false);
            $table->boolean('seller_insurance_frozen')->default(false);
            $table->string('previous_flow_status', 60)->nullable();
            $table->string('resolution_action', 50)->nullable();
            $table->text('resolution_note')->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_risk_investigation_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('investigation_id')->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('recipient_type', 20)->index();
            $table->unsignedBigInteger('recipient_id')->nullable()->index();
            $table->text('message');
            $table->unsignedBigInteger('support_ticket_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('order_admin_purchase_refunds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('customer_id')->index();
            $table->decimal('amount', 24, 3);
            $table->string('status', 30)->default('scheduled')->index();
            $table->dateTime('due_at')->index();
            $table->dateTime('completed_at')->nullable();
            $table->string('reference', 191)->nullable()->unique();
            $table->text('reason');
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_admin_purchase_refunds');
        Schema::dropIfExists('order_risk_investigation_messages');
        Schema::dropIfExists('order_risk_investigations');
        Schema::dropIfExists('order_admin_gate_decisions');
    }
};
