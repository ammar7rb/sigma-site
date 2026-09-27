<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'activation_status')) {
                $table->string('activation_status', 50)->default('not_required')->index()->after('order_status');
            }
            if (!Schema::hasColumn('orders', 'activation_pending_at')) {
                $table->timestamp('activation_pending_at')->nullable()->after('activation_status');
            }
            if (!Schema::hasColumn('orders', 'activation_completed_at')) {
                $table->timestamp('activation_completed_at')->nullable()->after('activation_pending_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'activation_completed_at',
                'activation_pending_at',
                'activation_status',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
