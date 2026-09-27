<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // CartShipping is a temporary quote, never an order record. Remove
        // choices produced by the former auto-selected Normal flow.
        DB::table('cart_shippings')->delete();
    }

    public function down(): void
    {
    }
};
