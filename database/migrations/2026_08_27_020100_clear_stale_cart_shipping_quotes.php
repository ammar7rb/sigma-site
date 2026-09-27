<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Cart shipping rows are temporary quotes. Remove legacy manual-method
        // selections so no customer sees an old fixed price before choosing an address.
        DB::table('cart_shippings')->delete();
    }

    public function down(): void
    {
        // Quotes are recreated from the selected address; nothing to restore.
    }
};
