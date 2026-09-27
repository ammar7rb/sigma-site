<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_insurances', function (Blueprint $table): void {
            $table->unsignedInteger('maturity_days')->default(90)->after('calculation_value');
            $table->timestamp('matures_at')->nullable()->index()->after('refunded_at');
            $table->timestamp('matured_at')->nullable()->after('matures_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_insurances', function (Blueprint $table): void {
            $table->dropColumn(['maturity_days', 'matures_at', 'matured_at']);
        });
    }
};
