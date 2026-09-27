<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_payment_methods', function (Blueprint $table) {
            $table->string('payment_channel', 20)->default('other')->after('method_name')->index();
        });

        DB::table('offline_payment_methods')
            ->where(function ($query) {
                $query->whereRaw('LOWER(method_name) LIKE ?', ['%wallet%'])
                    ->orWhere('method_name', 'like', '%محفظ%');
            })
            ->update(['payment_channel' => 'wallet']);

        DB::table('offline_payment_methods')
            ->where(function ($query) {
                $query->whereRaw('LOWER(method_name) LIKE ?', ['%insta%'])
                    ->orWhere('method_name', 'like', '%انستا%');
            })
            ->update(['payment_channel' => 'instapay']);
    }

    public function down(): void
    {
        Schema::table('offline_payment_methods', function (Blueprint $table) {
            $table->dropIndex(['payment_channel']);
            $table->dropColumn('payment_channel');
        });
    }
};
