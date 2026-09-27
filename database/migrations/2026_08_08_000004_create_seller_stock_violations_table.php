<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_stock_violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('alert_type', 32)->index();
            $table->string('decision', 32)->index();
            $table->string('policy_reference', 120)->nullable();
            $table->text('reason');
            $table->decimal('penalty_amount', 12, 2)->nullable();
            $table->unsignedBigInteger('decided_by')->index();
            $table->timestamp('detected_at')->index();
            $table->timestamp('decided_at')->index();
            $table->timestamps();
            $table->index(['seller_id', 'product_id', 'alert_type'], 'seller_stock_violation_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_stock_violations');
    }
};
