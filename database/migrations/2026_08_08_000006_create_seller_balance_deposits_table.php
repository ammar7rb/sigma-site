<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('seller_balance_deposits')) {
            return;
        }
        Schema::create('seller_balance_deposits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->decimal('amount', 40, 20);
            $table->string('currency_code', 20)->default('USD');
            $table->string('source', 20)->index();
            $table->string('status', 20)->index();
            $table->char('payment_request_id', 36)->nullable()->unique();
            $table->string('payment_method', 80)->nullable();
            $table->string('transaction_reference', 191)->nullable();
            $table->unsignedBigInteger('offline_payment_method_id')->nullable();
            $table->json('offline_information')->nullable();
            $table->json('payment_proof')->nullable();
            $table->text('payment_note')->nullable();
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_admin_id')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamps();
            $table->index(['seller_id', 'status', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_balance_deposits');
    }
};
