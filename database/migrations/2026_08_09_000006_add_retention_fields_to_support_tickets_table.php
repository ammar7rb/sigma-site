<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('status');
            $table->timestamp('archived_at')->nullable()->after('closed_at');
            $table->index(['status', 'closed_at', 'archived_at'], 'support_tickets_retention_index');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropIndex('support_tickets_retention_index');
            $table->dropColumn(['closed_at', 'archived_at']);
        });
    }
};
