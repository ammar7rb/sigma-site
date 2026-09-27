<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_purchase_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('package_price', 40, 20)->default(0);
            $table->decimal('purchase_limit', 40, 20)->default(0);
            $table->boolean('is_custom')->default(false)->index();
            $table->boolean('status')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_purchase_packages');
    }
};
