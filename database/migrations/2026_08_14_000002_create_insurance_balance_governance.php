<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('insurance_balance_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 20)->index();
            $table->unsignedBigInteger('subject_id')->index();
            $table->string('action', 40)->index();
            $table->decimal('amount', 40, 20);
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('order_insurance_id')->nullable()->index();
            $table->unsignedBigInteger('seller_order_insurance_id')->nullable()->index();
            $table->unsignedBigInteger('parent_action_id')->nullable()->index();
            $table->string('reference', 191)->unique();
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'insurance_actions_subject_created_index');
        });

        // Policy migration only. Historical movements remain immutable and are
        // reported as legacy; no historical amount is moved or deleted.
        if (Schema::hasColumn('order_insurances', 'balance_use_policy')) {
            DB::table('order_insurances')->update(['balance_use_policy' => 'insurance_only']);
        }
        if (Schema::hasColumn('seller_order_insurances', 'balance_use_policy')) {
            DB::table('seller_order_insurances')->update(['balance_use_policy' => 'insurance_only']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_balance_actions');
    }
};
