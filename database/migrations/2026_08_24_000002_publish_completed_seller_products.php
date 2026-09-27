<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Older seller API versions stored completed seller products as both
     * unpublished and pending review. Those products must remain in the
     * administrator review queue: a seller-visible active state is not a
     * public publishing approval.
     */
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasColumn('products', 'seller_review_status')
            || ! Schema::hasColumn('products', 'seller_reviewed_at')) {
            return;
        }

        $now = now();
        DB::table('products')
            ->where('added_by', 'seller')
            ->where('status', 0)
            ->where('request_status', 0)
            ->update([
                'seller_review_status' => 'submitted',
                'seller_reviewed_at' => null,
                'seller_submitted_at' => DB::raw('COALESCE(seller_submitted_at, created_at)'),
                'updated_at' => $now,
            ]);
    }

    /** This data repair must never hide valid products on rollback. */
    public function down(): void
    {
    }
};
