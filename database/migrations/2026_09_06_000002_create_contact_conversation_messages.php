<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('contact_conversation_messages', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('contact_id')->index();
            $table->string('sender', 20); $table->text('body'); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('contact_conversation_messages'); }
};
