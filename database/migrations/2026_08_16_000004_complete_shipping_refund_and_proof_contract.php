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
                if (! Schema::hasColumn('orders', 'shipping_promise_key')) {
                    $table->string('shipping_promise_key', 30)->nullable()->index();
                }
                if (! Schema::hasColumn('orders', 'sales_settlement_due_at')) {
                    $table->timestamp('sales_settlement_due_at')->nullable()->index();
                }
                if (! Schema::hasColumn('orders', 'shipping_due_separated')) {
                    $table->boolean('shipping_due_separated')->default(false);
                }
                if (! Schema::hasColumn('orders', 'shipping_due_override_reason')) {
                    $table->text('shipping_due_override_reason')->nullable();
                }
                if (! Schema::hasColumn('orders', 'seller_shipping_allocation')) {
                    $table->decimal('seller_shipping_allocation', 18, 4)->default(0);
                }
                if (! Schema::hasColumn('orders', 'platform_shipping_margin')) {
                    $table->decimal('platform_shipping_margin', 18, 4)->default(0);
                }
                if (! Schema::hasColumn('orders', 'shipping_workflow_status')) {
                    $table->string('shipping_workflow_status', 40)->default('pending')->index();
                }
            });
        }

        if (! Schema::hasTable('business_calendar_holidays')) {
            Schema::create('business_calendar_holidays', function (Blueprint $table) {
                $table->id();
                $table->date('holiday_date')->unique();
                $table->string('name');
                $table->boolean('active')->default(true)->index();
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_purchase_balances')) {
            Schema::create('customer_purchase_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id')->unique();
                $table->decimal('available_amount', 18, 4)->default(0);
                $table->decimal('held_amount', 18, 4)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_refund_ledgers')) {
            Schema::create('order_refund_ledgers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('customer_id')->index();
                $table->string('idempotency_key')->unique();
                $table->string('reason', 80)->index();
                $table->decimal('purchase_amount', 18, 4)->default(0);
                $table->decimal('insurance_amount', 18, 4)->default(0);
                $table->timestamp('purchase_available_at')->nullable()->index();
                $table->timestamp('insurance_available_at')->nullable()->index();
                $table->string('status', 30)->default('held')->index();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->text('admin_note')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_shipping_proofs')) {
            Schema::create('order_shipping_proofs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->string('shipping_status', 40)->index();
                $table->string('file_path');
                $table->string('disk', 30)->default('private');
                $table->string('original_name')->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->string('sha256', 64)->nullable()->index();
                $table->text('note')->nullable();
                $table->string('review_status', 30)->default('pending')->index();
                $table->unsignedBigInteger('reviewed_by_admin_id')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipping_proofs');
        Schema::dropIfExists('order_refund_ledgers');
        Schema::dropIfExists('customer_purchase_balances');
        Schema::dropIfExists('business_calendar_holidays');

        if (Schema::hasTable('orders')) {
            $columns = collect([
                'shipping_promise_key', 'sales_settlement_due_at', 'shipping_due_separated',
                'shipping_due_override_reason', 'seller_shipping_allocation',
                'platform_shipping_margin', 'shipping_workflow_status',
            ])->filter(fn (string $column) => Schema::hasColumn('orders', $column))->all();

            if ($columns !== []) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }
};
