<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('contacts', function (Blueprint $table) { $table->string('mobile_support_token_hash', 64)->nullable()->unique(); }); }
    public function down(): void { Schema::table('contacts', function (Blueprint $table) { $table->dropUnique(['mobile_support_token_hash']); $table->dropColumn('mobile_support_token_hash'); }); }
};
