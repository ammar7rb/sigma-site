<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'seller_shipping_response_status')) {
                $table->string('seller_shipping_response_status', 40)->nullable()->index();
            }
            if (! Schema::hasColumn('orders', 'seller_shipping_response_due_at')) {
                $table->timestamp('seller_shipping_response_due_at')->nullable()->index();
            }
            if (! Schema::hasColumn('orders', 'seller_shipping_response_at')) {
                $table->timestamp('seller_shipping_response_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'seller_shipping_rejection_reason')) {
                $table->text('seller_shipping_rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('orders', 'seller_shipping_support_ticket_id')) {
                $table->unsignedBigInteger('seller_shipping_support_ticket_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $columns = collect([
            'seller_shipping_response_status', 'seller_shipping_response_due_at',
            'seller_shipping_response_at', 'seller_shipping_rejection_reason',
            'seller_shipping_support_ticket_id',
        ])->filter(fn (string $column) => Schema::hasColumn('orders', $column))->all();

        if ($columns !== []) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
