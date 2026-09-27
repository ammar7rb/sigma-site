<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restore products that were automatically published by the temporary
     * seller-publication behaviour. Explicit administrator decisions have a
     * reviewer id and are intentionally left untouched.
     */
    public function up(): void
    {
        $requiredColumns = [
            'seller_review_status',
            'seller_reviewed_by',
            'seller_reviewed_at',
            'seller_submitted_at',
            'is_homepage_visible',
        ];

        if (! Schema::hasTable('products')) {
            return;
        }

        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn('products', $column)) {
                return;
            }
        }

        $now = now();
        DB::table('products')
            ->where('added_by', 'seller')
            ->where('request_status', 1)
            ->where('status', 1)
            ->where('seller_review_status', 'approved_published')
            ->whereNull('seller_reviewed_by')
            ->update([
                'request_status' => 0,
                'status' => 0,
                'featured' => 0,
                'is_homepage_visible' => 0,
                'seller_review_status' => 'submitted',
                'seller_reviewed_at' => null,
                'seller_submitted_at' => DB::raw('COALESCE(seller_submitted_at, created_at)'),
                'updated_at' => $now,
            ]);
    }

    /** The review queue is data-preserving and must not be reversed. */
    public function down(): void
    {
    }
};
