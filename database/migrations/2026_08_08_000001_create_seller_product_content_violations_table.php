<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_product_content_violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('category', 60);
            $table->string('source', 40)->default('seller_api');
            $table->timestamp('detected_at')->index();
            $table->timestamps();
            $table->index(['seller_id', 'category', 'detected_at'], 'seller_content_violation_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_product_content_violations');
    }
};
