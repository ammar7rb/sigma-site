<?php

namespace App\Services;

use App\Events\OrderStatusEvent;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\PostPurchaseInvoice;
use App\Models\User;
use App\Support\Commerce\OrderCommerceState;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles the second, post-purchase invoice introduced by commerce contract
 * post_purchase_v3.  The first payment remains merchandise + shipping only;
 * this invoice owns the tax and the customer-order insurance exclusively.
 */
class PostPurchaseInvoiceService
{
    public const CONTRACT_VERSION = 'post_purchase_v3';
    public const ORDER_STATUS_AWAITING_PAYMENT = 'awaiting_post_purchase_payment';
    public const ORDER_STATUS_AWAITING_ADMIN = 'awaiting_admin_order_review';
    public const ORDER_STATUS_RELEASED = 'released_to_seller';

    public function __construct(private readonly WorkflowFeatureService $workflowFeatures)
    {
    }

    public function isEnabled(): bool
    {
        return $this->workflowFeatures->postPurchaseTaxInvoiceEnabled()
            && $this->workflowFeatures->commerceContractVersion() === self::CONTRACT_VERSION;
    }

    public function usesSecondInvoice(Order $order): bool
    {
        return $order->commerce_flow_version === self::CONTRACT_VERSION;
    }

    /**
     * Creates an immutable tax/insurance snapshot only after the first
     * merchandise payment has really succeeded. Calling it twice is safe.
     *
     * @return array<int, PostPurchaseInvoice>
     */
    public function createForPaidOrderGroup(array $orderIds, bool $includeSubmittedOffline = false): array
    {
        if (! $this->isEnabled() || $orderIds === []) {
            return [];
        }

        return DB::transaction(function () use ($orderIds, $includeSubmittedOffline) {
            $invoices = [];

            foreach (array_values(array_unique(array_map('intval', $orderIds))) as $orderId) {
                $order = Order::query()->lockForUpdate()->find($orderId);
                if (! $order || ! $this->usesSecondInvoice($order) || ($order->payment_status !== 'paid'
                    && !($includeSubmittedOffline && $order->payment_method === 'offline_payment'
                        && \App\Models\OfflinePayments::where('order_id', $order->id)->exists()))) {
                    continue;
                }

                $existing = PostPurchaseInvoice::query()->where('order_id', $order->id)->lockForUpdate()->first();
                if ($existing) {
                    if ($order->payment_status === 'paid' && $existing->status === PostPurchaseInvoice::STATUS_PAID) {
                        $this->completeIfFullyPaid($existing);
                    }
                    $invoices[] = $existing;
                    continue;
                }

                $tax = $this->taxSnapshot($order);
                $insurance = $this->insuranceSnapshot($order);
                $total = round($tax['amount'] + $insurance['amount'], 4);

                // No tax and no insurance means there is no second payment
                // stage.  Do not manufacture a zero-value invoice or put the
                // customer into suspended orders; move the paid order directly
                // to the administration assignment gate instead.
                if ($total <= 0) {
                    if ($order->payment_status !== 'paid') continue;
                    $order->update([
                        'post_purchase_status' => self::ORDER_STATUS_AWAITING_ADMIN,
                        'order_status' => 'pending',
                        'commerce_flow_status' => OrderCommerceState::PENDING_ADMIN_REVIEW,
                        'commerce_flow_status_updated_at' => now(),
                        'admin_order_review_status' => 'pending_admin_review',
                        'operational_blocked_by' => 'admin_review',
                        'operational_block_reason' => 'seller_insurance_and_shipping_assignment_required',
                    ]);

                    continue;
                }

                $invoice = PostPurchaseInvoice::query()->create([
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'contract_version' => self::CONTRACT_VERSION,
                    'status' => PostPurchaseInvoice::STATUS_PENDING,
                    'taxable_amount' => $tax['taxable_amount'],
                    'tax_rate' => $tax['rate'],
                    'tax_amount' => $tax['amount'],
                    'insurance_amount' => $insurance['amount'],
                    'total_amount' => $total,
                    'paid_amount' => 0,
                    'payment_due_at' => now()->addDays($this->paymentDueDays()),
                    'paid_at' => null,
                    'tax_snapshot' => $tax,
                    'insurance_snapshot' => $insurance,
                    'metadata' => [
                        'first_payment_amount' => (float) $order->order_amount,
                        'first_payment_status' => $order->payment_status,
                        'insurance_balance_paid' => 0.0,
                        'external_paid' => 0.0,
                        'created_by' => 'post_purchase_invoice_service',
                    ],
                ]);

                $order->update([
                    'post_purchase_status' => self::ORDER_STATUS_AWAITING_PAYMENT,
                    'order_status' => 'pending',
                    'commerce_flow_status' => OrderCommerceState::CUSTOMER_INSURANCE_PENDING,
                    'commerce_flow_status_updated_at' => now(),
                    'admin_order_review_status' => 'waiting_customer_post_purchase_payment',
                    'operational_blocked_by' => 'customer_post_purchase_payment',
                    'operational_block_reason' => 'customer_tax_and_insurance_payment_required',
                ]);

                $invoices[] = $invoice;
            }

            return $invoices;
        });
    }

    /**
     * Safety net for an interrupted first-payment callback or an offline
     * payment approved through an older administration screen.  The unique
     * order_id index and the row lock keep this reconciliation idempotent.
     */
    public function createMissingInvoicesForPaidOrders(): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $orders = Order::query()
            ->where('commerce_flow_version', self::CONTRACT_VERSION)
            ->where('payment_status', 'paid')
            ->whereDoesntHave('postPurchaseInvoice')
            ->get();

        // Orders with neither tax nor customer insurance skip the second
        // invoice altogether.  They were already moved to the admin gate by
        // createForPaidOrderGroup(), so do not pick them up again on every
        // scheduler run.
        $ids = $orders
            ->filter(fn (Order $order): bool => $this->postPurchaseTotal($order) > 0)
            ->pluck('id')
            ->all();

        return count($this->createForPaidOrderGroup($ids));
    }

    private function postPurchaseTotal(Order $order): float
    {
        $tax = $this->taxSnapshot($order);
        $insurance = $this->insuranceSnapshot($order);

        return round((float) $tax['amount'] + (float) $insurance['amount'], 4);
    }

    /**
     * Uses the insurance wallet only against the insurance line. Tax can
     * never be paid from this balance, even if an invoice is partially paid.
     */
    public function applyCustomerInsuranceBalance(PostPurchaseInvoice $invoice, User $customer): PostPurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $customer) {
            $invoice = PostPurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertInvoiceCustomer($invoice, $customer);
            $this->assertPayable($invoice);

            $metadata = $invoice->metadata ?: [];
            $alreadyPaid = (float) ($metadata['insurance_balance_paid'] ?? 0);
            $availableForInsurance = max(0, round((float) $invoice->insurance_amount - $alreadyPaid, 4));
            if ($availableForInsurance <= 0) {
                return $invoice;
            }

            User::query()->lockForUpdate()->findOrFail($customer->id);
            $balance = $this->lockInsuranceBalance($customer->id);
            $ledgerAvailable = \Illuminate\Support\Facades\Schema::hasTable('customer_insurance_ledger_entries')
                ? max(0, (float) \App\Models\CustomerInsuranceLedgerEntry::where('customer_id', $customer->id)
                    ->selectRaw('COALESCE(SUM(credit - debit), 0) as balance')->value('balance')) : 0;
            $amount = min((float) $balance->available_amount + $ledgerAvailable, $availableForInsurance);
            if ($amount <= 0) {
                throw new DomainException('insufficient_customer_insurance_balance');
            }

            $before = (float) $balance->available_amount + $ledgerAvailable;
            $ledgerUsed = min($ledgerAvailable, $amount);
            if ($ledgerUsed > 0) {
                \App\Models\CustomerInsuranceLedgerEntry::create([
                    'customer_id' => $customer->id, 'order_id' => $invoice->order_id,
                    'entry_type' => 'insurance_payment_debit', 'credit' => 0, 'debit' => $ledgerUsed,
                    'reference' => 'post-purchase-insurance-' . $invoice->id . '-' . $alreadyPaid,
                    'metadata' => ['invoice_id' => $invoice->id, 'restricted_to' => 'insurance_payment'],
                ]);
            }
            $balance->update(['available_amount' => round((float) $balance->available_amount - ($amount - $ledgerUsed), 4)]);
            $this->recordInsuranceBalanceAction(
                subjectType: 'customer',
                subjectId: $customer->id,
                amount: -$amount,
                before: $before,
                after: round($before - $amount, 4),
                action: 'post_purchase_invoice_insurance_payment',
                orderId: $invoice->order_id,
                invoiceId: $invoice->id,
                metadata: ['tax_amount' => (float) $invoice->tax_amount],
            );

            $metadata['insurance_balance_paid'] = round($alreadyPaid + $amount, 4);
            $invoice->update([
                'paid_amount' => round((float) $invoice->paid_amount + $amount, 4),
                'metadata' => $metadata,
            ]);

            return $this->completeIfFullyPaid($invoice->fresh());
        });
    }

    /**
     * Records the non-insurance part through an approved payment callback or
     * an approved offline review. $amount must not include a wallet amount.
     */
    public function markExternalPaymentPaid(
        PostPurchaseInvoice $invoice,
        float $amount,
        string $paymentMethod,
        ?string $reference = null
    ): PostPurchaseInvoice {
        return DB::transaction(function () use ($invoice, $amount, $paymentMethod, $reference) {
            $invoice = PostPurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $isApprovedOfflineReview = $invoice->status === PostPurchaseInvoice::STATUS_AWAITING_REVIEW
                && str_starts_with($paymentMethod, 'offline_');
            if (! $isApprovedOfflineReview) {
                $this->assertPayable($invoice);
            }

            $metadata = $invoice->metadata ?: [];
            $insuranceWalletPaid = (float) ($metadata['insurance_balance_paid'] ?? 0);
            $externalAlreadyPaid = (float) ($metadata['external_paid'] ?? 0);
            $requiredExternal = max(0, round((float) $invoice->total_amount - $insuranceWalletPaid - $externalAlreadyPaid, 4));

            if (round($amount, 4) !== round($requiredExternal, 4)) {
                throw new DomainException('post_purchase_invoice_external_amount_mismatch');
            }

            $metadata['external_paid'] = round($externalAlreadyPaid + $amount, 4);
            $metadata['external_payment_method'] = $paymentMethod;
            $metadata['external_payment_reference'] = $reference;

            $invoice->update([
                'paid_amount' => round((float) $invoice->paid_amount + $amount, 4),
                'payment_method' => $paymentMethod,
                'payment_reference' => $reference ?: $invoice->payment_reference ?: Str::uuid()->toString(),
                'metadata' => $metadata,
            ]);

            return $this->completeIfFullyPaid($invoice->fresh());
        });
    }

    /**
     * Compatibility entry point for the older invoice screen.  An invoice paid
     * by the customer must enter the admin gate; it must never reveal an order
     * to the seller before the administrator assigns insurance and shipping.
     */
    public function releaseToSeller(PostPurchaseInvoice $invoice, int $adminId, ?string $note = null): PostPurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $adminId, $note) {
            $invoice = PostPurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== PostPurchaseInvoice::STATUS_PAID) {
                throw new DomainException('post_purchase_invoice_must_be_paid_before_admin_release');
            }

            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            if ($order->payment_status !== 'paid') {
                throw new DomainException('first_payment_must_be_approved');
            }
            $order->update([
                'post_purchase_status' => self::ORDER_STATUS_AWAITING_ADMIN,
                'order_status' => 'pending',
                'commerce_flow_status' => OrderCommerceState::PENDING_ADMIN_REVIEW,
                'commerce_flow_status_updated_at' => now(),
                'admin_order_review_status' => 'pending_admin_review',
                'operational_blocked_by' => 'admin_review',
                'operational_block_reason' => 'seller_insurance_and_shipping_assignment_required',
            ]);

            $metadata = $invoice->metadata ?: [];
            $metadata['queued_for_admin_gate_by'] = $adminId;
            $metadata['queued_for_admin_gate_at'] = now()->toIso8601String();
            $metadata['admin_gate_note'] = $note;
            $invoice->update(['metadata' => $metadata]);

            return $invoice->fresh();
        });
    }

    public function expireOverdueInvoices(): int
    {
        return PostPurchaseInvoice::query()
            ->whereIn('status', [PostPurchaseInvoice::STATUS_PENDING, PostPurchaseInvoice::STATUS_AWAITING_REVIEW])
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<', now())
            ->update(['status' => PostPurchaseInvoice::STATUS_EXPIRED]);
    }

    public function paymentSummary(PostPurchaseInvoice $invoice): array
    {
        $metadata = $invoice->metadata ?: [];
        $insuranceBalancePaid = (float) ($metadata['insurance_balance_paid'] ?? 0);

        return [
            'invoice_id' => $invoice->id,
            'first_payment_amount' => (float) ($metadata['first_payment_amount'] ?? 0),
            'order_id' => $invoice->order_id,
            'status' => $invoice->status,
            'tax_amount' => (float) $invoice->tax_amount,
            'insurance_amount' => (float) $invoice->insurance_amount,
            'insurance_balance_paid' => $insuranceBalancePaid,
            'external_amount_due' => max(0, round((float) $invoice->total_amount - $insuranceBalancePaid - (float) ($metadata['external_paid'] ?? 0), 4)),
            'total_amount' => (float) $invoice->total_amount,
            'paid_amount' => (float) $invoice->paid_amount,
            'payment_due_at' => $invoice->payment_due_at?->toIso8601String(),
            'insurance_balance_can_only_pay_insurance' => true,
        ];
    }

    public function customerInsuranceBalanceSummary(int $customerId): array
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('customer_insurance_ledger_entries')
            && \Illuminate\Support\Facades\Schema::hasTable('order_insurances')) {
            return app(CustomerInsuranceBalanceService::class)->summary($customerId);
        }
        $balance = DB::table('customer_insurance_balances')->where('customer_id', $customerId)->first();

        return [
            'available_balance' => (float) ($balance->available_amount ?? 0),
            'held_balance' => (float) ($balance->held_amount ?? 0),
            'next_maturity_at' => null,
            'withdrawable' => false,
            'allowed_uses' => ['order_insurance_only'],
        ];
    }

    private function completeIfFullyPaid(PostPurchaseInvoice $invoice): PostPurchaseInvoice
    {
        if (round((float) $invoice->paid_amount, 4) < round((float) $invoice->total_amount, 4)) {
            return $invoice;
        }

        $invoice->update([
            'status' => PostPurchaseInvoice::STATUS_PAID,
            'paid_at' => now(),
        ]);

        if (Order::whereKey($invoice->order_id)->value('payment_status') !== 'paid') {
            return $invoice->fresh();
        }

        if ((float) $invoice->insurance_amount > 0 && \Illuminate\Support\Facades\Schema::hasTable('order_insurances')) {
            $snapshot = $invoice->insurance_snapshot ?: [];
            $days = max(1, (int) ($snapshot['maturity_days'] ?? app(OrderInsuranceService::class)->maturityDays()));
            $insurance = \App\Models\OrderInsurance::firstOrCreate(['order_id' => $invoice->order_id], [
                'customer_id' => $invoice->customer_id, 'amount' => $invoice->insurance_amount,
                'order_amount' => $invoice->order->order_amount, 'threshold_amount' => $snapshot['threshold'] ?? 0,
                'calculation_type' => $snapshot['type'] ?? 'fixed', 'calculation_value' => $snapshot['value'] ?? $invoice->insurance_amount,
                'payment_method' => $invoice->payment_method ?: 'customer_insurance_balance',
                'payment_status' => 'paid', 'status' => 'held', 'maturity_days' => $days,
                'matures_at' => now()->addDays($days),
                'metadata' => ['post_purchase_invoice_id' => $invoice->id, 'restricted_to' => 'insurance_payment'],
            ]);
            app(CustomerInsuranceBalanceService::class)->recordHold($insurance);
        }

        Order::query()->whereKey($invoice->order_id)->update([
            'post_purchase_status' => self::ORDER_STATUS_AWAITING_ADMIN,
            'order_status' => 'pending',
            'commerce_flow_status' => OrderCommerceState::PENDING_ADMIN_REVIEW,
            'commerce_flow_status_updated_at' => now(),
            'admin_order_review_status' => 'pending_admin_review',
            'operational_blocked_by' => 'admin_review',
            'operational_block_reason' => 'seller_insurance_and_shipping_assignment_required',
        ]);

        return $invoice->fresh();
    }

    private function taxSnapshot(Order $order): array
    {
        $taxableAmount = max(0, round((float) $order->order_amount, 4));
        $amount = max(0, round((float) $order->total_tax_amount, 4));
        $rate = $taxableAmount > 0 ? round(($amount / $taxableAmount) * 100, 4) : 0.0;

        return [
            'type' => $order->tax_type,
            'model' => $order->tax_model,
            'taxable_amount' => $taxableAmount,
            'rate' => $rate,
            'amount' => $amount,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function insuranceSnapshot(Order $order): array
    {
        $enabled = $this->settingBool('customer_order_insurance_status', false);
        $threshold = max(0, $this->settingFloat('customer_order_insurance_threshold', 1000));
        $base = max(0, round((float) $order->order_amount, 4));
        $type = $this->setting('customer_order_insurance_type', 'percentage');
        $value = max(0, $this->settingFloat('customer_order_insurance_value', 0));
        $applies = $enabled && $base > 0 && ($threshold === 0.0 || $base >= $threshold);
        $amount = ! $applies ? 0.0 : ($type === 'fixed' ? $value : ($base * $value / 100));

        return [
            'enabled' => $enabled,
            'type' => $type === 'fixed' ? 'fixed' : 'percentage',
            'value' => $value,
            'threshold' => $threshold,
            'base_amount' => $base,
            'maturity_days' => app(OrderInsuranceService::class)->maturityDays(),
            'amount' => round($amount, 4),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function paymentDueDays(): int
    {
        return min(30, max(1, (int) $this->settingFloat('post_purchase_invoice_due_days', 2)));
    }

    private function assertPayable(PostPurchaseInvoice $invoice): void
    {
        if (! $invoice->isPayable()) {
            throw new DomainException('post_purchase_invoice_is_not_payable');
        }
    }

    private function assertInvoiceCustomer(PostPurchaseInvoice $invoice, User $customer): void
    {
        if ((int) $invoice->customer_id !== (int) $customer->id) {
            throw new DomainException('post_purchase_invoice_customer_mismatch');
        }
    }

    private function lockInsuranceBalance(int $customerId): object
    {
        $balance = DB::table('customer_insurance_balances')->where('customer_id', $customerId)->lockForUpdate()->first();
        if (! $balance) {
            DB::table('customer_insurance_balances')->insert([
                'customer_id' => $customerId,
                'available_amount' => 0,
                'held_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $balance = DB::table('customer_insurance_balances')->where('customer_id', $customerId)->lockForUpdate()->first();
        }

        return new class($balance) {
            public function __construct(private object $row) {}
            public function __get(string $key): mixed { return $this->row->{$key}; }
            public function update(array $values): void { DB::table('customer_insurance_balances')->where('customer_id', $this->row->customer_id)->update(array_merge($values, ['updated_at' => now()])); }
            public function fresh(): object { return DB::table('customer_insurance_balances')->where('customer_id', $this->row->customer_id)->first(); }
        };
    }

    private function recordInsuranceBalanceAction(
        string $subjectType,
        int $subjectId,
        float $amount,
        float $before,
        float $after,
        string $action,
        ?int $orderId = null,
        ?int $invoiceId = null,
        array $metadata = [],
    ): void {
        DB::table('insurance_balance_actions')->insert([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'order_id' => $orderId,
            'post_purchase_invoice_id' => $invoiceId,
            'action' => $action,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'status' => 'completed',
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $value = BusinessSetting::query()->where('type', $key)->value('value');
        if ($value === null || $value === '') {
            return $default;
        }

        return is_string($value) ? trim($value, "\"'") : $value;
    }

    private function settingFloat(string $key, float $default): float
    {
        return is_numeric($this->setting($key, $default)) ? (float) $this->setting($key, $default) : $default;
    }

    private function settingBool(string $key, bool $default): bool
    {
        return filter_var($this->setting($key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
