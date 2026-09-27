<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            if (!Schema::hasColumn('sellers', 'activation_status')) {
                $table->string('activation_status', 40)->default('pending_activation')->after('registration_reference');
            }

            if (!Schema::hasColumn('sellers', 'activation_requested_at')) {
                $table->timestamp('activation_requested_at')->nullable()->after('activation_status');
            }

            if (!Schema::hasColumn('sellers', 'activation_approved_at')) {
                $table->timestamp('activation_approved_at')->nullable()->after('activation_requested_at');
            }

            if (!Schema::hasColumn('sellers', 'activation_approved_by')) {
                $table->unsignedBigInteger('activation_approved_by')->nullable()->after('activation_approved_at');
            }
        });

        // Existing approved sellers must remain operational after this workflow is introduced.
        DB::table('sellers')
            ->where('status', 'approved')
            ->update([
                'activation_status' => 'active',
                'activation_approved_at' => DB::raw('COALESCE(activation_approved_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $columns = ['activation_status', 'activation_requested_at', 'activation_approved_at', 'activation_approved_by'];
            $existingColumns = array_filter($columns, fn (string $column) => Schema::hasColumn('sellers', $column));

            if ($existingColumns) {
                $table->dropColumn($existingColumns);
            }
        });
    }
};
