<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['seller_packages', 'seller_package_subscriptions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('duration_unit', 20)->nullable()->after('package_validity_days');
                $table->unsignedInteger('duration_value')->nullable()->after('duration_unit');
            });
        }
        foreach (['customer_purchase_packages', 'customer_purchase_package_subscriptions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('duration_unit', 20)->nullable();
                $table->unsignedInteger('duration_value')->nullable()->after('duration_unit');
            });
        }

        Schema::table('seller_package_subscriptions', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('starts_at');
        });

        Schema::table('customer_purchase_package_subscriptions', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_purchase_package_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['duration_unit', 'duration_value', 'started_at']);
        });
        Schema::table('customer_purchase_packages', function (Blueprint $table) {
            $table->dropColumn(['duration_unit', 'duration_value']);
        });
        Schema::table('seller_package_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['duration_unit', 'duration_value', 'started_at']);
        });
        Schema::table('seller_packages', function (Blueprint $table) {
            $table->dropColumn(['duration_unit', 'duration_value']);
        });
    }
};
