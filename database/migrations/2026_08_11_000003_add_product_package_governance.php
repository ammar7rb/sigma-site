<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'production_date')) {
            Schema::table('products', fn (Blueprint $table) => $table->date('production_date')->nullable()->after('minimum_order_qty')->index('products_production_date_idx'));
        }
        if (! Schema::hasColumn('products', 'expiry_date')) {
            Schema::table('products', fn (Blueprint $table) => $table->date('expiry_date')->nullable()->after('production_date')->index('products_expiry_date_idx'));
        }

        if (! Schema::hasColumn('seller_packages', 'search_priority')) {
            Schema::table('seller_packages', fn (Blueprint $table) => $table->unsignedInteger('search_priority')->default(100)->after('search_promotion_duration_days')->index('seller_packages_search_priority_idx'));
        }
        if (! Schema::hasColumn('seller_packages', 'cancellation_effect')) {
            Schema::table('seller_packages', fn (Blueprint $table) => $table->string('cancellation_effect', 40)->default('end_of_period')->after('duration_value'));
        }

        $subscriptionColumns = [
            'search_priority' => fn (Blueprint $table) => $table->unsignedInteger('search_priority')->default(100)->after('search_promotion_duration_days')->index('seller_subscriptions_search_priority_idx'),
            'cancellation_effect' => fn (Blueprint $table) => $table->string('cancellation_effect', 40)->default('end_of_period')->after('duration_value'),
            'cancel_at_period_end' => fn (Blueprint $table) => $table->boolean('cancel_at_period_end')->default(false)->after('cancellation_effect')->index('seller_subscriptions_cancel_at_end_idx'),
            'cancellation_requested_at' => fn (Blueprint $table) => $table->timestamp('cancellation_requested_at')->nullable()->after('cancel_at_period_end'),
            'cancellation_reason' => fn (Blueprint $table) => $table->text('cancellation_reason')->nullable()->after('cancellation_requested_at'),
            'cancellation_requested_by_type' => fn (Blueprint $table) => $table->string('cancellation_requested_by_type', 30)->nullable()->after('cancellation_reason'),
            'cancellation_requested_by_id' => fn (Blueprint $table) => $table->unsignedBigInteger('cancellation_requested_by_id')->nullable()->after('cancellation_requested_by_type')->index('seller_subscriptions_cancel_actor_idx'),
        ];
        foreach ($subscriptionColumns as $column => $definition) {
            if (! Schema::hasColumn('seller_package_subscriptions', $column)) {
                Schema::table('seller_package_subscriptions', $definition);
            }
        }

        if (! Schema::hasColumn('seller_product_promotions', 'search_priority')) {
            Schema::table('seller_product_promotions', fn (Blueprint $table) => $table->unsignedInteger('search_priority')->default(100)->after('sort_order')->index('seller_promotions_search_priority_idx'));
        }

        if (! Schema::hasTable('seller_package_product_metrics')) {
            Schema::create('seller_package_product_metrics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index('package_metrics_seller_idx');
                $table->unsignedBigInteger('seller_package_subscription_id')->index('package_metrics_subscription_idx');
                $table->unsignedBigInteger('product_id')->index('package_metrics_product_idx');
                $table->date('metric_date')->index('package_metrics_date_idx');
                $table->unsignedBigInteger('impressions')->default(0);
                $table->unsignedBigInteger('visits')->default(0);
                $table->timestamps();

                $table->unique(
                    ['seller_package_subscription_id', 'product_id', 'metric_date'],
                    'seller_package_product_metrics_daily_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_package_product_metrics');

        Schema::table('seller_product_promotions', function (Blueprint $table) {
            $table->dropColumn('search_priority');
        });
        Schema::table('seller_package_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'search_priority',
                'cancellation_effect',
                'cancel_at_period_end',
                'cancellation_requested_at',
                'cancellation_reason',
                'cancellation_requested_by_type',
                'cancellation_requested_by_id',
            ]);
        });
        Schema::table('seller_packages', function (Blueprint $table) {
            $table->dropColumn(['search_priority', 'cancellation_effect']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['production_date', 'expiry_date']);
        });
    }
};
