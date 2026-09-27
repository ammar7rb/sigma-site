<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'post_purchase_status')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('post_purchase_status', 50)->default('legacy')->index()->after('commerce_flow_version');
            });
        }

        if (! Schema::hasTable('customer_insurance_balances')) {
            Schema::create('customer_insurance_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id')->unique();
                $table->decimal('available_amount', 18, 4)->default(0);
                $table->decimal('held_amount', 18, 4)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('insurance_balance_actions')) {
            Schema::create('insurance_balance_actions', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type', 20)->index();
                $table->unsignedBigInteger('subject_id')->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('post_purchase_invoice_id')->nullable()->index();
                $table->string('action', 100)->index();
                $table->decimal('amount', 18, 4);
                $table->decimal('balance_before', 18, 4);
                $table->decimal('balance_after', 18, 4);
                $table->string('status', 30)->default('completed')->index();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->text('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_balance_actions');
        Schema::dropIfExists('customer_insurance_balances');

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'post_purchase_status')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('post_purchase_status'));
        }
    }
};
