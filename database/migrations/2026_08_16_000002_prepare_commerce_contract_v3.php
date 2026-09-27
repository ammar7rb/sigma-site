<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'commerce_flow_version')) {
                    $table->string('commerce_flow_version', 40)->default('legacy_v1')->index();
                }
                if (! Schema::hasColumn('orders', 'shipping_settlement_due_at')) {
                    $table->timestamp('shipping_settlement_due_at')->nullable()->index();
                }
                if (! Schema::hasColumn('orders', 'shipping_eta_from')) {
                    $table->timestamp('shipping_eta_from')->nullable();
                }
                if (! Schema::hasColumn('orders', 'shipping_eta_to')) {
                    $table->timestamp('shipping_eta_to')->nullable();
                }
                if (! Schema::hasColumn('orders', 'shipping_duration_snapshot')) {
                    $table->json('shipping_duration_snapshot')->nullable();
                }
            });
        }

        if (! Schema::hasTable('post_purchase_invoices')) {
            Schema::create('post_purchase_invoices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique();
                $table->unsignedBigInteger('customer_id')->index();
                $table->string('contract_version', 40)->default('post_purchase_v3');
                $table->string('status', 30)->default('pending')->index();
                $table->decimal('taxable_amount', 18, 4)->default(0);
                $table->decimal('tax_rate', 8, 4)->default(0);
                $table->decimal('tax_amount', 18, 4)->default(0);
                $table->decimal('insurance_amount', 18, 4)->default(0);
                $table->decimal('total_amount', 18, 4)->default(0);
                $table->decimal('paid_amount', 18, 4)->default(0);
                $table->timestamp('payment_due_at')->nullable()->index();
                $table->timestamp('paid_at')->nullable();
                $table->string('payment_method', 60)->nullable();
                $table->string('payment_reference')->nullable()->unique();
                $table->json('tax_snapshot')->nullable();
                $table->json('insurance_snapshot')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_purchase_invoices');

        if (Schema::hasTable('orders')) {
            $columns = collect([
                'commerce_flow_version', 'shipping_settlement_due_at', 'shipping_eta_from',
                'shipping_eta_to', 'shipping_duration_snapshot',
            ])->filter(fn (string $column) => Schema::hasColumn('orders', $column))->all();

            if ($columns !== []) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }
};
