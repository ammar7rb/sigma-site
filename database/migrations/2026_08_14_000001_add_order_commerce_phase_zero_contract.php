<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('commerce_flow_version', 40)->default('legacy-v1')->index()->after('order_status');
            $table->string('commerce_flow_status', 60)->nullable()->index()->after('commerce_flow_version');
            $table->dateTime('commerce_flow_status_updated_at')->nullable()->after('commerce_flow_status');
            $table->string('admin_order_review_status', 40)->default('legacy_not_required')->index()->after('commerce_flow_status_updated_at');
            $table->unsignedBigInteger('admin_order_reviewed_by')->nullable()->index()->after('admin_order_review_status');
            $table->dateTime('admin_order_reviewed_at')->nullable()->after('admin_order_reviewed_by');
            $table->text('admin_order_review_note')->nullable()->after('admin_order_reviewed_at');
        });

        Schema::table('order_insurances', function (Blueprint $table): void {
            $table->dateTime('payment_due_at')->nullable()->index()->after('payment_status');
            $table->dateTime('payment_completed_at')->nullable()->after('payment_due_at');
            $table->unsignedBigInteger('support_ticket_id')->nullable()->index()->after('payment_completed_at');
            $table->string('balance_use_policy', 40)->default('legacy_mixed')->after('support_ticket_id');
            $table->string('purchase_refund_status', 40)->default('not_applicable')->index()->after('balance_use_policy');
            $table->dateTime('purchase_refund_due_at')->nullable()->index()->after('purchase_refund_status');
            $table->dateTime('purchase_refund_completed_at')->nullable()->after('purchase_refund_due_at');
            $table->string('purchase_refund_reference', 191)->nullable()->index()->after('purchase_refund_completed_at');
            $table->string('confiscation_status', 40)->default('none')->index()->after('purchase_refund_reference');
            $table->decimal('confiscated_amount', 40, 20)->default(0)->after('confiscation_status');
            $table->unsignedBigInteger('confiscated_by_admin_id')->nullable()->index()->after('confiscated_amount');
            $table->dateTime('confiscated_at')->nullable()->after('confiscated_by_admin_id');
            $table->text('confiscation_reason')->nullable()->after('confiscated_at');
        });

        Schema::table('seller_order_insurances', function (Blueprint $table): void {
            $table->string('amount_source', 40)->default('legacy_rule')->index()->after('amount');
            $table->string('admin_override_type', 30)->nullable()->after('amount_source');
            $table->decimal('admin_override_value', 24, 3)->nullable()->after('admin_override_type');
            $table->unsignedBigInteger('admin_decided_by')->nullable()->index()->after('admin_override_value');
            $table->dateTime('admin_decided_at')->nullable()->after('admin_decided_by');
            $table->text('admin_decision_reason')->nullable()->after('admin_decided_at');
            $table->string('balance_use_policy', 40)->default('legacy_mixed')->after('admin_decision_reason');
            $table->string('confiscation_status', 40)->default('none')->index()->after('balance_use_policy');
            $table->decimal('confiscated_amount', 24, 3)->default(0)->after('confiscation_status');
            $table->unsignedBigInteger('confiscated_by_admin_id')->nullable()->index()->after('confiscated_amount');
            $table->dateTime('confiscated_at')->nullable()->after('confiscated_by_admin_id');
            $table->text('confiscation_reason')->nullable()->after('confiscated_at');
        });

        Schema::table('seller_product_promotions', function (Blueprint $table): void {
            $table->string('approval_status', 40)->default('legacy_auto_approved')->index()->after('status');
            $table->dateTime('requested_at')->nullable()->after('approval_status');
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable()->index()->after('requested_at');
            $table->dateTime('reviewed_at')->nullable()->after('reviewed_by_admin_id');
            $table->text('review_note')->nullable()->after('reviewed_at');
            $table->string('placement_scope', 40)->nullable()->index()->after('review_note');
            $table->string('placement_key', 191)->nullable()->index()->after('placement_scope');
            $table->unsignedInteger('requested_position')->nullable()->after('placement_key');
            $table->unsignedInteger('approved_position')->nullable()->after('requested_position');
        });

        // Existing records remain explicitly legacy. No historical amount,
        // status, entitlement or promotion is reinterpreted by this migration.
        DB::table('orders')->whereNull('commerce_flow_status')->update([
            'commerce_flow_version' => 'legacy-v1',
            'admin_order_review_status' => 'legacy_not_required',
        ]);
    }

    public function down(): void
    {
        Schema::table('seller_product_promotions', function (Blueprint $table): void {
            $table->dropIndex(['approval_status']);
            $table->dropIndex(['reviewed_by_admin_id']);
            $table->dropIndex(['placement_scope']);
            $table->dropIndex(['placement_key']);
            $table->dropColumn([
                'approval_status', 'requested_at', 'reviewed_by_admin_id', 'reviewed_at', 'review_note',
                'placement_scope', 'placement_key', 'requested_position', 'approved_position',
            ]);
        });

        Schema::table('seller_order_insurances', function (Blueprint $table): void {
            $table->dropIndex(['amount_source']);
            $table->dropIndex(['admin_decided_by']);
            $table->dropIndex(['confiscation_status']);
            $table->dropIndex(['confiscated_by_admin_id']);
            $table->dropColumn([
                'amount_source', 'admin_override_type', 'admin_override_value', 'admin_decided_by',
                'admin_decided_at', 'admin_decision_reason', 'balance_use_policy', 'confiscation_status',
                'confiscated_amount', 'confiscated_by_admin_id', 'confiscated_at', 'confiscation_reason',
            ]);
        });

        Schema::table('order_insurances', function (Blueprint $table): void {
            $table->dropIndex(['payment_due_at']);
            $table->dropIndex(['support_ticket_id']);
            $table->dropIndex(['purchase_refund_status']);
            $table->dropIndex(['purchase_refund_due_at']);
            $table->dropIndex(['purchase_refund_reference']);
            $table->dropIndex(['confiscation_status']);
            $table->dropIndex(['confiscated_by_admin_id']);
            $table->dropColumn([
                'payment_due_at', 'payment_completed_at', 'support_ticket_id', 'balance_use_policy',
                'purchase_refund_status', 'purchase_refund_due_at', 'purchase_refund_completed_at',
                'purchase_refund_reference', 'confiscation_status', 'confiscated_amount',
                'confiscated_by_admin_id', 'confiscated_at', 'confiscation_reason',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['commerce_flow_version']);
            $table->dropIndex(['commerce_flow_status']);
            $table->dropIndex(['admin_order_review_status']);
            $table->dropIndex(['admin_order_reviewed_by']);
            $table->dropColumn([
                'commerce_flow_version', 'commerce_flow_status', 'commerce_flow_status_updated_at',
                'admin_order_review_status', 'admin_order_reviewed_by', 'admin_order_reviewed_at',
                'admin_order_review_note',
            ]);
        });
    }
};
