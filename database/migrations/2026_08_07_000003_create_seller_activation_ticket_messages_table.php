<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_activation_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_activation_ticket_id')->index('satm_ticket_id_idx');
            $table->string('sender_type', 20)->index();
            $table->unsignedBigInteger('sender_admin_id')->nullable()->index('satm_admin_id_idx');
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->boolean('is_automatic')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_activation_ticket_messages');
    }
};
