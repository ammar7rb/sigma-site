<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id(); $table->string('key', 80)->index(); $table->string('title'); $table->longText('content');
            $table->string('audience', 20)->default('both'); $table->unsignedInteger('version'); $table->boolean('is_required')->default(true); $table->boolean('is_active')->default(true); $table->timestamp('effective_at')->nullable(); $table->unsignedBigInteger('created_by_admin_id')->nullable(); $table->timestamps(); $table->unique(['key', 'version']);
        });
        Schema::create('policy_acceptances', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('policy_version_id')->index(); $table->string('subject_type', 30); $table->unsignedBigInteger('subject_id'); $table->timestamp('accepted_at'); $table->timestamps(); $table->unique(['policy_version_id', 'subject_type', 'subject_id'], 'policy_acceptance_once');
        });
    }
    public function down(): void { Schema::dropIfExists('policy_acceptances'); Schema::dropIfExists('policy_versions'); }
};
