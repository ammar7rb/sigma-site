<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_daily_metrics')) {
            Schema::create('platform_daily_metrics', function (Blueprint $table): void {
                $table->id();
                $table->date('metric_date');
                $table->string('channel', 20);
                $table->unsignedBigInteger('page_views')->default(0);
                $table->unsignedBigInteger('requests')->default(0);
                $table->timestamps();
                $table->unique(['metric_date', 'channel']);
            });
        }

        Schema::table('seller_ledger_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('seller_ledger_entries', 'reporting_category')) {
                $table->string('reporting_category', 32)->nullable()->after('event_type')->index();
            }
            if (! Schema::hasColumn('seller_ledger_entries', 'reference_code')) {
                $table->string('reference_code', 100)->nullable()->after('reference_id')->index();
            }
        });

        Schema::table('seller_settlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('seller_settlements', 'timezone_snapshot')) {
                $table->string('timezone_snapshot', 64)->nullable()->after('due_at');
            }
            if (! Schema::hasColumn('seller_settlements', 'pre_due_days_snapshot')) {
                $table->unsignedTinyInteger('pre_due_days_snapshot')->default(5)->after('timezone_snapshot');
            }
            if (! Schema::hasColumn('seller_settlements', 'escalated_at')) {
                $table->timestamp('escalated_at')->nullable()->after('overdue_notified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_settlements', function (Blueprint $table): void {
            foreach (['timezone_snapshot', 'pre_due_days_snapshot', 'escalated_at'] as $column) {
                if (Schema::hasColumn('seller_settlements', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('seller_ledger_entries', function (Blueprint $table): void {
            foreach (['reporting_category', 'reference_code'] as $column) {
                if (Schema::hasColumn('seller_ledger_entries', $column)) $table->dropColumn($column);
            }
        });
        Schema::dropIfExists('platform_daily_metrics');
    }
};
