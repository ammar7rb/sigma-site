<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('account_activation_documents')
            && ! Schema::hasColumn('account_activation_documents', 'custom_name')) {
            Schema::table('account_activation_documents', function (Blueprint $table) {
                $table->string('custom_name', 120)->nullable()->after('document_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('account_activation_documents')
            && Schema::hasColumn('account_activation_documents', 'custom_name')) {
            Schema::table('account_activation_documents', function (Blueprint $table) {
                $table->dropColumn('custom_name');
            });
        }
    }
};
