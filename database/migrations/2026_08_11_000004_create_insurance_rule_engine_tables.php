<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('insurance_rules')) {
            Schema::create('insurance_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('subject_type', 20)->index();
                $table->string('name', 191);
                $table->string('code', 100);
                $table->string('rule_type', 30)->default('default')->index();
                $table->unsignedInteger('priority')->default(1000)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->decimal('minimum_order_amount', 24, 3)->default(0);
                $table->decimal('maximum_order_amount', 24, 3)->nullable();
                $table->unsignedInteger('minimum_account_age_days')->default(0);
                $table->unsignedInteger('maximum_account_age_days')->nullable();
                $table->string('calculation_type', 20)->default('percentage');
                $table->decimal('calculation_value', 24, 6)->default(0);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['subject_type', 'code'], 'insurance_rules_subject_code_unique');
            });
        }

        if (! Schema::hasTable('insurance_incentives')) {
            Schema::create('insurance_incentives', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 191);
                $table->string('code', 100)->unique();
                $table->string('incentive_type', 30)->index();
                $table->string('value_type', 20)->default('fixed');
                $table->decimal('value', 24, 6)->default(0);
                $table->decimal('maximum_amount', 24, 3)->nullable();
                $table->unsignedInteger('priority')->default(1000)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('new_accounts_only')->default(false);
                $table->unsignedInteger('minimum_account_age_days')->default(0);
                $table->unsignedInteger('maximum_account_age_days')->nullable();
                $table->unsignedInteger('usage_limit_per_customer')->default(1);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('insurance_incentive_redemptions')) {
            Schema::create('insurance_incentive_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('insurance_incentive_id')->index();
                $table->unsignedBigInteger('customer_id')->index();
                $table->unsignedBigInteger('order_insurance_id')->nullable()->index();
                $table->string('redemption_type', 30)->index();
                $table->decimal('amount', 24, 3)->default(0);
                $table->string('reference', 150)->unique();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('order_insurances')) {
            Schema::table('order_insurances', function (Blueprint $table): void {
                if (! Schema::hasColumn('order_insurances', 'insurance_rule_id')) {
                    $table->unsignedBigInteger('insurance_rule_id')->nullable()->after('customer_id')->index();
                }
                if (! Schema::hasColumn('order_insurances', 'insurance_incentive_id')) {
                    $table->unsignedBigInteger('insurance_incentive_id')->nullable()->after('insurance_rule_id')->index();
                }
                if (! Schema::hasColumn('order_insurances', 'original_amount')) {
                    $table->decimal('original_amount', 40, 20)->default(0)->after('amount');
                }
                if (! Schema::hasColumn('order_insurances', 'discount_amount')) {
                    $table->decimal('discount_amount', 40, 20)->default(0)->after('original_amount');
                }
                if (! Schema::hasColumn('order_insurances', 'rule_snapshot')) {
                    $table->json('rule_snapshot')->nullable()->after('metadata');
                }
            });
        }

        if (Schema::hasTable('seller_order_insurances')) {
            Schema::table('seller_order_insurances', function (Blueprint $table): void {
                if (! Schema::hasColumn('seller_order_insurances', 'insurance_rule_id')) {
                    $table->unsignedBigInteger('insurance_rule_id')->nullable()->after('seller_id')->index();
                }
                if (! Schema::hasColumn('seller_order_insurances', 'calculation_type')) {
                    $table->string('calculation_type', 20)->default('percentage')->after('percentage');
                }
                if (! Schema::hasColumn('seller_order_insurances', 'calculation_value')) {
                    $table->decimal('calculation_value', 24, 6)->default(0)->after('calculation_type');
                }
                if (! Schema::hasColumn('seller_order_insurances', 'rule_snapshot')) {
                    $table->json('rule_snapshot')->nullable()->after('metadata');
                }
            });
        }

        $this->seedDefaultRules();
    }

    private function seedDefaultRules(): void
    {
        if (! Schema::hasTable('business_settings') || ! Schema::hasTable('insurance_rules')) {
            return;
        }

        $settings = DB::table('business_settings')
            ->whereIn('type', [
                'customer_order_insurance_threshold', 'customer_order_insurance_calculation_type',
                'customer_order_insurance_calculation_value', 'seller_order_insurance_percentage',
                'seller_order_insurance_calculation_type', 'seller_order_insurance_calculation_value',
            ])->pluck('value', 'type');

        DB::table('insurance_rules')->updateOrInsert(
            ['subject_type' => 'customer', 'code' => 'customer-default'],
            [
                'name' => 'Customer default insurance', 'rule_type' => 'default', 'priority' => 1000,
                'is_active' => true,
                'minimum_order_amount' => (float) ($settings['customer_order_insurance_threshold'] ?? 1000),
                'calculation_type' => $settings['customer_order_insurance_calculation_type'] ?? 'percentage',
                'calculation_value' => (float) ($settings['customer_order_insurance_calculation_value'] ?? 0),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        DB::table('insurance_rules')->updateOrInsert(
            ['subject_type' => 'seller', 'code' => 'seller-default'],
            [
                'name' => 'Seller default order insurance', 'rule_type' => 'default', 'priority' => 1000,
                'is_active' => true, 'minimum_order_amount' => 0,
                'calculation_type' => $settings['seller_order_insurance_calculation_type'] ?? 'percentage',
                'calculation_value' => (float) ($settings['seller_order_insurance_calculation_value']
                    ?? $settings['seller_order_insurance_percentage'] ?? 0),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('seller_order_insurances')) {
            Schema::table('seller_order_insurances', function (Blueprint $table): void {
                foreach (['insurance_rule_id', 'calculation_type', 'calculation_value', 'rule_snapshot'] as $column) {
                    if (Schema::hasColumn('seller_order_insurances', $column)) $table->dropColumn($column);
                }
            });
        }
        if (Schema::hasTable('order_insurances')) {
            Schema::table('order_insurances', function (Blueprint $table): void {
                foreach (['insurance_rule_id', 'insurance_incentive_id', 'original_amount', 'discount_amount', 'rule_snapshot'] as $column) {
                    if (Schema::hasColumn('order_insurances', $column)) $table->dropColumn($column);
                }
            });
        }
        Schema::dropIfExists('insurance_incentive_redemptions');
        Schema::dropIfExists('insurance_incentives');
        Schema::dropIfExists('insurance_rules');
    }
};
