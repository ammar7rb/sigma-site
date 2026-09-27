<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vendor_withdraw_method_infos')) {
            return;
        }
        Schema::table('vendor_withdraw_method_infos', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_withdraw_method_infos', 'method_type')) {
                $table->string('method_type', 32)->default('bank')->after('method_name');
            }
            if (!Schema::hasColumn('vendor_withdraw_method_infos', 'approval_status')) {
                $table->string('approval_status', 20)->default('pending')->after('method_type');
            }
            if (!Schema::hasColumn('vendor_withdraw_method_infos', 'approval_reason')) {
                $table->text('approval_reason')->nullable()->after('approval_status');
            }
            if (!Schema::hasColumn('vendor_withdraw_method_infos', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('approval_reason');
            }
            if (!Schema::hasColumn('vendor_withdraw_method_infos', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }
            if (!Schema::hasColumn('vendor_withdraw_method_infos', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            }
            $table->index(['user_id', 'approval_status', 'is_active'], 'vendor_withdraw_approval_lookup');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('vendor_withdraw_method_infos')) {
            return;
        }
        Schema::table('vendor_withdraw_method_infos', function (Blueprint $table) {
            $table->dropIndex('vendor_withdraw_approval_lookup');
            foreach (['method_type', 'approval_status', 'approval_reason', 'submitted_at', 'reviewed_at', 'reviewed_by'] as $column) {
                if (Schema::hasColumn('vendor_withdraw_method_infos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
