<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seller_packages', function (Blueprint $table) {
            // Zero means this package does not allow the seller to create discount coupons.
            $table->unsignedInteger('coupon_limit')->default(0)->after('homepage_promotion_duration_days');
        });

        Schema::table('seller_package_subscriptions', function (Blueprint $table) {
            // Snapshot coupon quota so later package edits never change an active seller subscription.
            $table->unsignedInteger('coupon_limit')->default(0)->after('homepage_promotion_duration_days');
            $table->unsignedInteger('used_coupon_limit')->default(0)->after('coupon_limit');
            $table->integer('coupon_adjustment_limit')->default(0)->after('used_coupon_limit');
        });
    }

    public function down(): void
    {
        Schema::table('seller_package_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['coupon_limit', 'used_coupon_limit', 'coupon_adjustment_limit']);
        });

        Schema::table('seller_packages', function (Blueprint $table) {
            $table->dropColumn('coupon_limit');
        });
    }
};
