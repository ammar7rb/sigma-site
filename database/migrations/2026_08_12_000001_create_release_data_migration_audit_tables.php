<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('release_data_migration_runs')) {
            Schema::create('release_data_migration_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('batch_key', 100)->unique();
                $table->string('status', 30)->default('running')->index();
                $table->json('summary')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('rolled_back_at')->nullable();
                $table->unsignedBigInteger('initiated_by_admin_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('release_data_migration_items')) {
            Schema::create('release_data_migration_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('release_data_migration_run_id')->index('release_migration_items_run_idx');
                $table->string('action', 80);
                $table->string('table_name', 100);
                $table->unsignedBigInteger('record_id');
                $table->json('before_values');
                $table->json('after_values');
                $table->string('status', 30)->default('applied')->index();
                $table->timestamps();

                $table->unique(
                    ['release_data_migration_run_id', 'action', 'table_name', 'record_id'],
                    'release_migration_items_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('release_data_migration_items');
        Schema::dropIfExists('release_data_migration_runs');
    }
};
