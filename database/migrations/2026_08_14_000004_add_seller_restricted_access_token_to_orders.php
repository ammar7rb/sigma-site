<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'seller_restricted_access_token')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->uuid('seller_restricted_access_token')->nullable()->unique()->after('commerce_flow_status_updated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'seller_restricted_access_token')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('seller_restricted_access_token'));
        }
    }
};
