<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('withdraw_requests') && !Schema::hasColumn('withdraw_requests', 'vendor_withdraw_method_info_id')) {
            Schema::table('withdraw_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('vendor_withdraw_method_info_id')->nullable()->after('withdrawal_method_id');
                $table->index('vendor_withdraw_method_info_id', 'withdraw_requests_vendor_method_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('withdraw_requests') && Schema::hasColumn('withdraw_requests', 'vendor_withdraw_method_info_id')) {
            Schema::table('withdraw_requests', function (Blueprint $table) {
                $table->dropIndex('withdraw_requests_vendor_method_idx');
                $table->dropColumn('vendor_withdraw_method_info_id');
            });
        }
    }
};
