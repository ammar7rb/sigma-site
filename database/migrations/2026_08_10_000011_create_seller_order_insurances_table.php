<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_order_insurances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('seller_id')->index();
            $table->decimal('amount', 24, 3);
            $table->decimal('order_amount', 24, 3);
            $table->decimal('percentage', 8, 4);
            $table->string('status', 40)->default('pending_payment')->index();
            $table->string('payment_status', 20)->default('unpaid')->index();
            $table->string('payment_method', 80)->nullable();
            $table->string('payment_reference', 191)->nullable();
            $table->uuid('payment_request_id')->nullable()->index();
            $table->unsignedSmallInteger('pending_days')->default(3);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->text('admin_note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'seller_id'], 'seller_order_insurances_order_seller_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_order_insurances');
    }
};
