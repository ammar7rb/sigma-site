<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_order_insurance_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_order_insurance_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('action', 50)->index();
            $table->string('status_before', 40);
            $table->string('status_after', 40);
            $table->text('reason');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_order_insurance_decisions');
    }
};
