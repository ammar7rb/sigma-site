<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_balance_deposits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('wallet_type', 20);
            $table->decimal('amount', 24, 3);
            $table->decimal('submitted_amount', 24, 3);
            $table->string('currency_code', 20);
            $table->unsignedBigInteger('offline_payment_method_id');
            $table->string('method_name');
            $table->json('method_information')->nullable();
            $table->string('payment_reference', 191);
            $table->unique(['offline_payment_method_id', 'payment_reference'], 'customer_deposit_payment_reference_unique');
            $table->string('payment_proof');
            $table->text('payment_note')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('request_key', 100)->unique();
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('customer_balance_deposits'); }
};
