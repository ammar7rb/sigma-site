<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_ledger_entries')) {
            return;
        }

        Schema::create('seller_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('bucket', 32);
            $table->string('direction', 8);
            $table->decimal('amount', 40, 20);
            $table->string('event_type', 64);
            $table->string('group_key', 191);
            $table->char('idempotency_key', 64)->unique();
            $table->string('reference_type', 128)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('entry_hash', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'bucket', 'created_at']);
            $table->index(['seller_id', 'group_key']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_ledger_entries');
    }
};
