<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_settlements', function (Blueprint $table) {
            $table->timestamp('pre_due_notified_at')->nullable()->after('due_at');
            $table->timestamp('overdue_notified_at')->nullable()->after('pre_due_notified_at');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable()->after('sent_to')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('seller_id');
        });
        Schema::table('seller_settlements', function (Blueprint $table) {
            $table->dropColumn(['pre_due_notified_at', 'overdue_notified_at']);
        });
    }
};
