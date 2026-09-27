<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable()->after('referred_by');
            }
            if (!Schema::hasColumn('users', 'privacy_accepted_at')) {
                $table->timestamp('privacy_accepted_at')->nullable()->after('terms_accepted_at');
            }
            if (!Schema::hasColumn('users', 'monthly_insurance_started_at')) {
                $table->timestamp('monthly_insurance_started_at')->nullable()->after('privacy_accepted_at');
            }
            if (!Schema::hasColumn('users', 'monthly_insurance_paid_until')) {
                $table->timestamp('monthly_insurance_paid_until')->nullable()->index()->after('monthly_insurance_started_at');
            }
            if (!Schema::hasColumn('users', 'monthly_insurance_last_paid_at')) {
                $table->timestamp('monthly_insurance_last_paid_at')->nullable()->after('monthly_insurance_paid_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'monthly_insurance_last_paid_at',
                'monthly_insurance_paid_until',
                'monthly_insurance_started_at',
                'privacy_accepted_at',
                'terms_accepted_at',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
