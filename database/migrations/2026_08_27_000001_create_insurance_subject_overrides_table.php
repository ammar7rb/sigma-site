<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('insurance_subject_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('mode', 20); // exempt or required
            $table->string('calculation_type', 20)->nullable();
            $table->decimal('calculation_value', 12, 3)->nullable();
            $table->string('reason', 500);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id', 'is_active'], 'ins_subj_override_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_subject_overrides');
    }
};
