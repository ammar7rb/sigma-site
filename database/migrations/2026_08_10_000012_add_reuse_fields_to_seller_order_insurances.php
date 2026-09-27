<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seller_order_insurances', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reuse_after_days')->default(90)->after('pending_days');
            $table->timestamp('reusable_at')->nullable()->index()->after('expires_at');
            $table->timestamp('reusable_released_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('seller_order_insurances', function (Blueprint $table): void {
            $table->dropColumn(['reuse_after_days', 'reusable_at', 'reusable_released_at']);
        });
    }
};
