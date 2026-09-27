<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_activation_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->string('status', 40)->default('open')->index();
            $table->string('subject')->nullable();
            $table->unsignedBigInteger('assigned_admin_id')->nullable()->index();
            $table->unsignedBigInteger('approved_by_admin_id')->nullable()->index();
            $table->text('decision_note')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_activation_tickets');
    }
};
