<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_insurances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->decimal('amount', 40, 20);
            $table->decimal('order_amount', 40, 20);
            $table->decimal('threshold_amount', 40, 20);
            $table->string('calculation_type', 20);
            $table->decimal('calculation_value', 40, 20);
            $table->string('payment_method', 60);
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('status', 30)->default('pending_payment')->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->text('admin_note')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('order_insurances'); }
};
