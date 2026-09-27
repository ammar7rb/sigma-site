<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Product specifications and lifecycle dates were introduced by earlier
        // migrations (2026_08_08_000003 and 2026_08_11_000003).  Re-adding
        // them here makes an upgraded database fail with duplicate columns.
        // This migration owns only the audit trail for product-review decisions.
        if (! Schema::hasTable('product_review_decisions')) {
            Schema::create('product_review_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedBigInteger('seller_id')->nullable()->index();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->string('decision', 32)->index();
                $table->text('reason')->nullable();
                $table->unsignedTinyInteger('from_request_status')->nullable();
                $table->unsignedTinyInteger('to_request_status')->nullable();
                $table->unsignedTinyInteger('from_status')->nullable();
                $table->unsignedTinyInteger('to_status')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_review_decisions');
    }
};
