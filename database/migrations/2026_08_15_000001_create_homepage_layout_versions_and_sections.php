<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_layout_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('theme', 40)->index();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            $table->unsignedBigInteger('published_by_admin_id')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['theme', 'version']);
            $table->index(['theme', 'status']);
        });

        Schema::create('homepage_layout_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('homepage_layout_version_id')->constrained('homepage_layout_versions')->cascadeOnDelete();
            $table->string('section_key', 80);
            $table->boolean('is_visible')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->string('source_mode', 20)->default('automatic');
            $table->string('automatic_source', 40)->nullable();
            $table->json('product_ids')->nullable();
            $table->json('banner_ids')->nullable();
            $table->string('locale', 10)->default('all');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['homepage_layout_version_id', 'section_key'], 'homepage_layout_sections_version_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_layout_sections');
        Schema::dropIfExists('homepage_layout_versions');
    }
};
