<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_insurances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->uuid('transaction_id')->unique();
            $table->decimal('amount', 40, 20)->default(0);
            $table->string('status', 50)->default('pending')->index();
            $table->string('payment_status', 50)->default('unpaid')->index();
            $table->string('payment_method', 191)->nullable();
            $table->string('payment_reference', 191)->nullable()->index();
            $table->char('payment_request_id', 36)->nullable()->index();
            $table->text('forfeiture_reason')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->timestamp('forfeited_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'seller_insurances_seller_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_insurances');
    }
};
