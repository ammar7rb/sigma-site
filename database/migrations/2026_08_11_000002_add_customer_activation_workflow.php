<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'activation_status')) {
                $table->string('activation_status', 40)->default('pending_support')->index()->after('is_active');
                $table->timestamp('activation_requested_at')->nullable()->after('activation_status');
                $table->timestamp('activation_approved_at')->nullable()->after('activation_requested_at');
                $table->unsignedBigInteger('activation_approved_by')->nullable()->after('activation_approved_at');
            }
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            if (!Schema::hasColumn('support_tickets', 'purpose')) {
                $table->string('purpose', 40)->default('general')->index()->after('type');
                $table->string('review_status', 40)->nullable()->index()->after('status');
                $table->text('review_note')->nullable()->after('review_status');
                $table->unsignedBigInteger('reviewed_by_admin_id')->nullable()->after('review_note');
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_admin_id');
            }
        });

        // Existing customers predate this workflow and must not be forced back
        // into activation. New registrations explicitly start pending_support.
        DB::table('users')->update([
            'activation_status' => 'active',
            'activation_approved_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $columns = ['purpose', 'review_status', 'review_note', 'reviewed_by_admin_id', 'reviewed_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('support_tickets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $columns = ['activation_status', 'activation_requested_at', 'activation_approved_at', 'activation_approved_by'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
