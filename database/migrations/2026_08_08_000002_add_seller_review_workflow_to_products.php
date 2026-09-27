<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('seller_review_status', 32)->nullable()->index()->after('request_status');
            $table->text('seller_review_reason')->nullable()->after('denied_note');
            $table->timestamp('seller_submitted_at')->nullable()->after('seller_review_reason');
            $table->timestamp('seller_reviewed_at')->nullable()->after('seller_submitted_at');
            $table->unsignedBigInteger('seller_reviewed_by')->nullable()->index()->after('seller_reviewed_at');
        });

        Schema::create('seller_product_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('seller_id')->index();
            $table->string('status', 32)->index();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->timestamp('decided_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_product_review_decisions');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'seller_review_status',
                'seller_review_reason',
                'seller_submitted_at',
                'seller_reviewed_at',
                'seller_reviewed_by',
            ]);
        });
    }
};
