<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\Seller;
use App\Models\SellerOrderInsurance;
use App\Models\SellerOrderInsuranceDecision;
use App\Models\Notification;
use App\Support\Commerce\OrderCommerceState;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SellerOrderInsuranceService
{
    public function __construct(private readonly SellerLedgerService $ledger) {}

    public function settings(): array
    {
        return [
            'enabled' => (bool) (BusinessSetting::query()->where('type', 'seller_order_insurance_status')->value('value') ?? false),
            'percentage' => (float) (BusinessSetting::query()->where('type', 'seller_order_insurance_percentage')->value('value') ?? 0),
            'calculation_type' => BusinessSetting::query()->where('type', 'seller_order_insurance_calculation_type')->value('value') ?: 'percentage',
            'calculation_value' => (float) (BusinessSetting::query()->where('type', 'seller_order_insurance_calculation_value')->value('value')
                ?? BusinessSetting::query()->where('type', 'seller_order_insurance_percentage')->value('value') ?? 0),
            'pending_days' => max(1, (int) (BusinessSetting::query()->where('type', 'seller_order_insurance_pending_days')->value('value') ?? 3)),
            'reuse_after_days' => max(1, (int) (BusinessSetting::query()->where('type', 'seller_order_insurance_reuse_after_days')->value('value') ?? 90)),
            'expiry_action' => BusinessSetting::query()->where('type', 'seller_order_insurance_expiry_action')->value('value') ?: 'admin_review',
        ];
    }

    /** @return array<string, mixed> */
    public function preview(Order $order, Seller $seller): array
    {
        $settings = $this->settings();
        $orderAmount = round(max(0, (float) $order->order_amount), 2);
        if (! $settings['enabled']) {
            return [
                'applicable' => false, 'amount' => 0.0, 'order_amount' => $orderAmount,
                'calculation_type' => $settings['calculation_type'],
                'calculation_value' => $settings['calculation_value'],
                'rule_id' => null, 'rule_snapshot' => ['settings' => $settings],
            ];
        }

        $calculation = app(InsuranceRuleEngineService::class)->resolve(
            subjectType: 'seller', orderAmount: $orderAmount, accountCreatedAt: $seller->created_at,
            fallback: [
                'code' => 'seller-legacy-default', 'rule_type' => 'default', 'priority' => 1000,
                'minimum_order_amount' => 0, 'maximum_order_amount' => null,
                'minimum_account_age_days' => 0, 'maximum_account_age_days' => null,
                'calculation_type' => $settings['calculation_type'],
                'calculation_value' => $settings['calculation_value'],
            ],
            subjectId: $seller->id,
        );

        return $calculation + ['order_amount' => $orderAmount, 'settings' => $settings];
    }

    public function createFromAdminDecision(Order $order, Seller $seller, array $decision, ?int $adminId): SellerOrderInsurance
    {
        if ((int) $order->seller_id !== (int) $seller->id || $order->seller_is !== 'seller') {
            throw new DomainException('seller_order_insurance_order_not_found');
        }

        $mode = (string) ($decision['mode'] ?? 'default');
        if (! in_array($mode, ['default', 'manual', 'waive'], true)) {
            throw new DomainException('invalid_seller_order_insurance_admin_mode');
        }
        $reason = trim((string) ($decision['reason'] ?? ''));
        if ($mode !== 'default' && $reason === '') {
            throw new DomainException('seller_order_insurance_override_reason_required');
        }

        return DB::transaction(function () use ($order, $seller, $decision, $adminId, $mode, $reason): SellerOrderInsurance {
            $settings = $this->settings();
            $preview = $this->preview($order, $seller);
            $orderAmount = round(max(0, (float) $order->order_amount), 2);
            $type = (string) ($preview['calculation_type'] ?? 'percentage');
            $value = (float) ($preview['calculation_value'] ?? 0);
            $amount = (float) ($preview['amount'] ?? 0);
            $source = 'default_rule';

            if ($mode === 'default' && ! ($preview['applicable'] ?? false)) {
                throw new DomainException('seller_order_insurance_rule_is_not_configured');
            }
            if ($mode === 'manual') {
                $type = (string) ($decision['calculation_type'] ?? 'fixed');
                $value = round((float) ($decision['calculation_value'] ?? 0), 3);
                if (! in_array($type, ['percentage', 'fixed'], true) || $value < 0 || ($type === 'percentage' && $value > 100)) {
                    throw new DomainException('invalid_seller_order_insurance_manual_value');
                }
                $amount = round($type === 'percentage' ? $orderAmount * $value / 100 : $value, 2);
                $source = 'admin_override';
                if ($value === 0.0) {
                    $mode = 'waive';
                    $source = 'admin_waiver';
                }
            } elseif ($mode === 'waive') {
                $type = 'fixed'; $value = 0; $amount = 0; $source = 'admin_waiver';
            }

            $existing = SellerOrderInsurance::query()->where('order_id', $order->id)->where('seller_id', $seller->id)->lockForUpdate()->first();
            if ($existing && in_array($existing->status, [SellerOrderInsurance::STATUS_PAID, SellerOrderInsurance::STATUS_WAIVED], true)) {
                if ($existing->status === SellerOrderInsurance::STATUS_WAIVED && $mode === 'waive') return $existing;
                throw new DomainException('seller_order_insurance_admin_decision_is_locked');
            }

            $attributes = [
                'order_id' => $order->id, 'seller_id' => $seller->id, 'order_amount' => $orderAmount,
                'percentage' => $type === 'percentage' ? $value : 0, 'amount' => $amount,
                'amount_source' => $source, 'admin_override_type' => $mode === 'manual' ? $type : null,
                'admin_override_value' => $mode === 'manual' ? $value : null,
                'admin_decided_by' => $adminId, 'admin_decided_at' => now(),
                'admin_decision_reason' => $mode === 'default' ? null : $reason,
                'insurance_rule_id' => $mode === 'default' ? ($preview['rule_id'] ?? null) : null,
                'calculation_type' => $type, 'calculation_value' => $value,
                'status' => $mode === 'waive' ? SellerOrderInsurance::STATUS_WAIVED : SellerOrderInsurance::STATUS_PENDING_PAYMENT,
                'payment_status' => $mode === 'waive' ? 'waived' : 'unpaid',
                'payment_method' => $mode === 'waive' ? 'admin_waiver' : null,
                'pending_days' => $settings['pending_days'], 'reuse_after_days' => $settings['reuse_after_days'],
                'expires_at' => $mode === 'waive' ? null : now()->addDays($settings['pending_days']),
                'balance_use_policy' => 'insurance_only',
                'metadata' => ['settings_snapshot' => $settings, 'admin_decision_mode' => $mode, 'withdrawable' => false, 'restricted_to' => 'seller_order_insurance'],
                'rule_snapshot' => $mode === 'default' ? ($preview['rule_snapshot'] ?? []) : ['manual' => ['type' => $type, 'value' => $value, 'reason' => $reason]],
            ];

            if ($existing) {
                $existing->fill($attributes)->save();
                return $existing->fresh();
            }
            return SellerOrderInsurance::query()->create($attributes);
        });
    }

    public function getOrCreate(Order $order, Seller $seller): ?SellerOrderInsurance
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return null;
        }
        if ((int) $order->seller_id !== (int) $seller->id || $order->seller_is !== 'seller') {
            throw new DomainException('seller_order_insurance_order_not_found');
        }
        return DB::transaction(function () use ($order, $seller, $settings): SellerOrderInsurance {
            $existing = SellerOrderInsurance::query()
                ->where('order_id', $order->id)
                ->where('seller_id', $seller->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $orderAmount = round(max(0, (float) $order->order_amount), 2);
            $calculation = app(InsuranceRuleEngineService::class)->resolve(
                subjectType: 'seller', orderAmount: $orderAmount, accountCreatedAt: $seller->created_at,
                fallback: [
                    'code' => 'seller-legacy-default', 'rule_type' => 'default', 'priority' => 1000,
                    'minimum_order_amount' => 0, 'maximum_order_amount' => null,
                    'minimum_account_age_days' => 0, 'maximum_account_age_days' => null,
                    'calculation_type' => $settings['calculation_type'],
                    'calculation_value' => $settings['calculation_value'],
                ],
                subjectId: $seller->id,
            );
            if (! $calculation['applicable']) {
                throw new DomainException('seller_order_insurance_rule_is_not_configured');
            }
            $attributes = [
                'order_id' => $order->id,
                'seller_id' => $seller->id,
                'order_amount' => $orderAmount,
                'percentage' => $calculation['calculation_type'] === 'percentage' ? $calculation['calculation_value'] : 0,
                'amount' => $calculation['amount'],
                'status' => SellerOrderInsurance::STATUS_PENDING_PAYMENT,
                'payment_status' => 'unpaid',
                'pending_days' => $settings['pending_days'],
                'reuse_after_days' => $settings['reuse_after_days'],
                'expires_at' => now()->addDays($settings['pending_days']),
                'metadata' => ['settings_snapshot' => $settings, 'withdrawable' => false, 'restricted_to' => 'seller_order_insurance'],
            ];
            if (Schema::hasColumn('seller_order_insurances', 'balance_use_policy')) {
                $attributes['balance_use_policy'] = 'insurance_only';
            }
            if (Schema::hasColumn('seller_order_insurances', 'insurance_rule_id')) {
                $attributes += [
                    'insurance_rule_id' => $calculation['rule_id'],
                    'calculation_type' => $calculation['calculation_type'],
                    'calculation_value' => $calculation['calculation_value'],
                    'rule_snapshot' => $calculation['rule_snapshot'],
                ];
            }
            return SellerOrderInsurance::query()->create($attributes);
        });
    }

    public function canViewDetails(?SellerOrderInsurance $insurance): bool
    {
        return ! $insurance || in_array($insurance->status, [SellerOrderInsurance::STATUS_PAID, SellerOrderInsurance::STATUS_WAIVED], true);
    }

    public function payload(SellerOrderInsurance $insurance): array
    {
        $summary = $this->ledger->summary((int) $insurance->seller_id);
        return [
            'id' => $insurance->id, 'order_id' => $insurance->order_id,
            'order_reference' => 'ORD-' . $insurance->order_id,
            'order_amount' => (float) $insurance->order_amount, 'amount' => (float) $insurance->amount,
            'calculation_type' => $insurance->calculation_type ?: 'percentage',
            'calculation_value' => (float) ($insurance->calculation_value ?: $insurance->percentage),
            'rule_id' => $insurance->insurance_rule_id, 'rule_snapshot' => $insurance->rule_snapshot,
            'status' => $insurance->status, 'payment_status' => $insurance->payment_status,
            'payment_method' => $insurance->payment_method, 'payment_reference' => $insurance->payment_reference,
            'pending_days' => $insurance->pending_days, 'reuse_after_days' => $insurance->reuse_after_days,
            'expires_at' => $insurance->expires_at?->toIso8601String(),
            'paid_at' => $insurance->paid_at?->toIso8601String(),
            'reusable_at' => $insurance->reusable_at?->toIso8601String(),
            'reusable_released_at' => $insurance->reusable_released_at?->toIso8601String(),
            'can_view_order_details' => $this->canViewDetails($insurance),
            'details_hidden' => ! $this->canViewDetails($insurance),
            'admin_note' => $insurance->admin_note,
            'latest_decision' => $insurance->decisions()->first()?->only(['action', 'reason', 'status_after', 'created_at']),
            'balances' => [
                'operating' => (float) ($summary[SellerLedgerService::OPERATING] ?? 0),
                'reusable_insurance_credit' => (float) ($summary[SellerLedgerService::ORDER_INSURANCE_CREDIT] ?? 0),
            ],
            'withdrawable' => false, 'restricted_to' => 'seller_order_insurance',
        ];
    }

    public function markPaid(SellerOrderInsurance $insurance, string $paymentMethod, ?string $reference = null, ?int $adminId = null, ?string $note = null): SellerOrderInsurance
    {
        $this->assertNotUnderInvestigation($insurance);
        return DB::transaction(function () use ($insurance, $paymentMethod, $reference, $adminId, $note): SellerOrderInsurance {
            $locked = SellerOrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if (in_array($locked->status, [SellerOrderInsurance::STATUS_PAID, SellerOrderInsurance::STATUS_WAIVED], true)) {
                return $locked;
            }
            if ($locked->status === SellerOrderInsurance::STATUS_EXPIRED) {
                throw new DomainException('seller_order_insurance_has_expired');
            }

            $locked->update([
                'status' => SellerOrderInsurance::STATUS_PAID,
                'payment_status' => 'paid',
                'payment_method' => $paymentMethod,
                'payment_reference' => $reference,
                'paid_at' => now(),
                'reusable_at' => now()->addDays((int) $locked->reuse_after_days),
                'reviewed_by_admin_id' => $adminId,
                'admin_note' => $note,
            ]);

            $this->syncOrderState($locked, OrderCommerceState::RELEASED_TO_SELLER);
            $this->notifySellerPaymentResult($locked, 'approved');

            return $locked->fresh();
        });
    }

    public function payFromOperatingBalance(SellerOrderInsurance $insurance): SellerOrderInsurance
    {
        throw new DomainException('seller_operating_balance_cannot_pay_insurance');
    }

    /** Releases a paid deposit only to the restricted insurance-credit bucket; it is never withdrawable. */
    public function releaseReusableCredits(?int $limit = null): int
    {
        $query = SellerOrderInsurance::query()->where('status', SellerOrderInsurance::STATUS_PAID)
            ->whereNotNull('reusable_at')->where('reusable_at', '<=', now())->whereNull('reusable_released_at')->orderBy('id');
        if ($limit) $query->limit($limit);
        $released = 0;
        foreach ($query->get() as $insurance) {
            $done = DB::transaction(function () use ($insurance): bool {
                $locked = SellerOrderInsurance::query()->lockForUpdate()->find($insurance->id);
                if (! $locked || $locked->reusable_released_at || $locked->reusable_at?->isFuture()) return false;
                $this->ledger->lockSellerFinancialState((int) $locked->seller_id);
                $this->ledger->post(
                    sellerId: (int) $locked->seller_id, eventType: 'seller_order_insurance_credit_released',
                    groupKey: 'seller-order-insurance:' . $locked->id . ':credit-release',
                    movements: [['bucket' => SellerLedgerService::ORDER_INSURANCE_CREDIT, 'direction' => 'credit', 'amount' => (float) $locked->amount]],
                    referenceType: SellerOrderInsurance::class, referenceId: (int) $locked->id,
                    metadata: ['order_id' => $locked->order_id, 'restricted_to' => 'seller_order_insurance'],
                );
                $locked->update(['reusable_released_at' => now()]);
                return true;
            });
            $released += $done ? 1 : 0;
        }
        return $released;
    }

    public function payFromReusableCredit(SellerOrderInsurance $insurance): SellerOrderInsurance
    {
        $this->assertNotUnderInvestigation($insurance);
        $this->releaseReusableCredits();
        return DB::transaction(function () use ($insurance): SellerOrderInsurance {
            $locked = SellerOrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($locked->status !== SellerOrderInsurance::STATUS_PENDING_PAYMENT || $locked->payment_status !== 'unpaid') throw new DomainException('seller_order_insurance_payment_is_not_available');
            $this->ledger->lockSellerFinancialState((int) $locked->seller_id);
            try { $this->ledger->assertSufficient((int) $locked->seller_id, SellerLedgerService::ORDER_INSURANCE_CREDIT, (float) $locked->amount); }
            catch (\InvalidArgumentException $exception) { throw new DomainException('seller_order_insurance_credit_insufficient', previous: $exception); }
            $this->ledger->post(
                sellerId: (int) $locked->seller_id, eventType: 'seller_order_insurance_credit_used', groupKey: 'seller-order-insurance:' . $locked->id . ':credit-use',
                movements: [['bucket' => SellerLedgerService::ORDER_INSURANCE_CREDIT, 'direction' => 'debit', 'amount' => (float) $locked->amount]],
                referenceType: SellerOrderInsurance::class, referenceId: (int) $locked->id,
                metadata: ['order_id' => $locked->order_id, 'restricted_to' => 'seller_order_insurance'],
            );
            $locked->update(['status' => SellerOrderInsurance::STATUS_PAID, 'payment_status' => 'paid', 'payment_method' => 'seller_order_insurance_credit', 'payment_reference' => 'seller-order-insurance-credit-' . $locked->id, 'paid_at' => now(), 'reusable_at' => now()->addDays((int) $locked->reuse_after_days)]);
            $this->syncOrderState($locked, OrderCommerceState::RELEASED_TO_SELLER);
            return $locked->fresh();
        });
    }

    public function attachPaymentRequest(SellerOrderInsurance $insurance, string $paymentRequestId): SellerOrderInsurance
    {
        $this->assertNotUnderInvestigation($insurance);
        return DB::transaction(function () use ($insurance, $paymentRequestId): SellerOrderInsurance {
            $locked = SellerOrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($locked->status === SellerOrderInsurance::STATUS_PENDING_PAYMENT && $locked->payment_status === 'unpaid') {
                $locked->update(['payment_request_id' => $paymentRequestId]);
            }
            return $locked->fresh();
        });
    }

    public function submitOfflinePayment(SellerOrderInsurance $insurance, array $offlinePayment): SellerOrderInsurance
    {
        $this->assertNotUnderInvestigation($insurance);
        return DB::transaction(function () use ($insurance, $offlinePayment): SellerOrderInsurance {
            $locked = SellerOrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($locked->status !== SellerOrderInsurance::STATUS_PENDING_PAYMENT || $locked->payment_status !== 'unpaid') {
                throw new DomainException('seller_order_insurance_payment_is_not_available');
            }
            if ($locked->expires_at?->isPast()) {
                $locked->update(['status' => SellerOrderInsurance::STATUS_EXPIRED, 'expired_at' => now()]);
                throw new DomainException('seller_order_insurance_has_expired');
            }
            $metadata = $locked->metadata ?: [];
            $metadata['offline_payment'] = $offlinePayment;
            $locked->update([
                'status' => SellerOrderInsurance::STATUS_PENDING_REVIEW,
                'payment_method' => 'offline_payment',
                'payment_reference' => 'offline-review:' . ($offlinePayment['method_id'] ?? 'manual'),
                'metadata' => $metadata,
            ]);
            $this->syncOrderState($locked, OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW);
            return $locked->fresh();
        });
    }

    public function rejectOfflinePayment(SellerOrderInsurance $insurance, int $adminId, string $note): SellerOrderInsurance
    {
        return DB::transaction(function () use ($insurance, $adminId, $note): SellerOrderInsurance {
            $locked = SellerOrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($locked->status !== SellerOrderInsurance::STATUS_PENDING_REVIEW || $locked->payment_status !== 'unpaid') {
                throw new DomainException('seller_order_insurance_offline_review_not_found');
            }
            $metadata = $locked->metadata ?: [];
            $metadata['offline_payment_review'] = ['action' => 'rejected', 'reviewed_by_admin_id' => $adminId, 'reviewed_at' => now()->toDateTimeString(), 'note' => $note];
            $locked->update(['status' => SellerOrderInsurance::STATUS_PENDING_PAYMENT, 'payment_method' => null, 'payment_reference' => null, 'reviewed_by_admin_id' => $adminId, 'admin_note' => $note, 'metadata' => $metadata]);
            $this->syncOrderState($locked, OrderCommerceState::SELLER_INSURANCE_PENDING);
            $this->notifySellerPaymentResult($locked, 'rejected');
            return $locked->fresh();
        });
    }

    private function syncOrderState(SellerOrderInsurance $insurance, string $state): void
    {
        $order = Order::query()->find($insurance->order_id);
        if (! $order || ! in_array($order->commerce_flow_version, [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION], true)) {
            return;
        }
        $order->forceFill([
            'commerce_flow_status' => $state,
            'commerce_flow_status_updated_at' => now(),
            'operational_blocked_by' => in_array($state, [OrderCommerceState::SELLER_INSURANCE_PENDING, OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW], true) ? 'seller_insurance' : null,
            'operational_block_reason' => $state === OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW
                ? 'seller_order_insurance_payment_under_review'
                : ($state === OrderCommerceState::SELLER_INSURANCE_PENDING ? 'seller_order_insurance_payment_required' : null),
        ])->save();
        if ($state === OrderCommerceState::RELEASED_TO_SELLER) {
            $order->forceFill([
                'post_purchase_status' => $order->commerce_flow_version === PostPurchaseInvoiceService::CONTRACT_VERSION
                    ? PostPurchaseInvoiceService::ORDER_STATUS_RELEASED
                    : $order->post_purchase_status,
                'order_status' => 'confirmed',
            ])->save();
            app(SellerShippingResponseService::class)->startResponseWindow($order->fresh());
        }
    }

    public function recordPaymentFailure(SellerOrderInsurance $insurance, array $paymentData): void
    {
        DB::transaction(function () use ($insurance, $paymentData): void {
            $locked = SellerOrderInsurance::query()->lockForUpdate()->find($insurance->id);
            if (! $locked || $locked->payment_status === 'paid') return;
            $metadata = $locked->metadata ?: [];
            $metadata['last_payment_failure'] = ['payment_request_id' => $paymentData['id'] ?? null, 'payment_method' => $paymentData['payment_method'] ?? null, 'failed_at' => now()->toDateTimeString()];
            $locked->update(['metadata' => $metadata]);
        });
    }

    public function expireDue(?int $limit = null): int
    {
        $query = SellerOrderInsurance::query()
            ->whereIn('status', [SellerOrderInsurance::STATUS_PENDING_PAYMENT, SellerOrderInsurance::STATUS_PENDING_REVIEW])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id');
        if ($limit) {
            $query->limit($limit);
        }

        $count = 0;
        foreach ($query->get() as $candidate) {
            $updated = SellerOrderInsurance::query()
                ->whereKey($candidate->id)
                ->whereIn('status', [SellerOrderInsurance::STATUS_PENDING_PAYMENT, SellerOrderInsurance::STATUS_PENDING_REVIEW])
                ->update(['status' => SellerOrderInsurance::STATUS_EXPIRED, 'expired_at' => now()]);
            $count += $updated ? 1 : 0;
        }

        return $count;
    }

    /**
     * Records the mandatory administrative decision for an expired, unpaid
     * seller order insurance without ever exposing order details implicitly.
     */
    public function decideExpired(
        SellerOrderInsurance $insurance,
        string $action,
        string $reason,
        ?int $adminId,
        ?int $extensionDays = null,
    ): SellerOrderInsuranceDecision {
        $allowed = ['extend', 'waive', 'keep_locked', 'transfer_to_operations', 'request_cancellation'];
        if (! in_array($action, $allowed, true)) {
            throw new DomainException('invalid_seller_order_insurance_decision');
        }
        if (trim($reason) === '') {
            throw new DomainException('seller_order_insurance_decision_reason_required');
        }

        return DB::transaction(function () use ($insurance, $action, $reason, $adminId, $extensionDays): SellerOrderInsuranceDecision {
            $locked = SellerOrderInsurance::query()->with('order')->lockForUpdate()->findOrFail($insurance->id);
            if ($locked->status !== SellerOrderInsurance::STATUS_EXPIRED || $locked->payment_status === 'paid') {
                throw new DomainException('seller_order_insurance_expired_decision_not_available');
            }

            $before = $locked->status;
            $payload = [];
            $updates = [
                'reviewed_by_admin_id' => $adminId,
                'admin_note' => $reason,
            ];

            if ($action === 'extend') {
                $days = max(1, min(90, (int) $extensionDays));
                $updates += [
                    'status' => SellerOrderInsurance::STATUS_PENDING_PAYMENT,
                    'expires_at' => now()->addDays($days),
                    'expired_at' => null,
                ];
                $payload['extension_days'] = $days;
                $payload['new_expires_at'] = now()->addDays($days)->toIso8601String();
            } elseif ($action === 'waive') {
                $updates += [
                    'status' => SellerOrderInsurance::STATUS_WAIVED,
                    'payment_status' => 'waived',
                ];
            } else {
                $metadata = $locked->metadata ?: [];
                $metadata['administrative_queue'] = $action === 'transfer_to_operations' ? 'operations' : null;
                $updates['metadata'] = $metadata;
                if ($action === 'request_cancellation') {
                    $metadata['administrative_queue'] = 'order_cancellation';
                    $updates['metadata'] = $metadata;
                    $payload['cancellation_requested'] = true;
                }
            }

            $locked->update($updates);
            $after = $locked->fresh()->status;
            $decision = SellerOrderInsuranceDecision::query()->create([
                'seller_order_insurance_id' => $locked->id,
                'order_id' => $locked->order_id,
                'seller_id' => $locked->seller_id,
                'admin_id' => $adminId,
                'action' => $action,
                'status_before' => $before,
                'status_after' => $after,
                'reason' => $reason,
                'payload' => $payload,
            ]);

            $this->notifySellerDecision($locked->fresh(), $decision);
            return $decision;
        });
    }

    private function notifySellerDecision(SellerOrderInsurance $insurance, SellerOrderInsuranceDecision $decision): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Notification::query()->create([
            'sent_by' => 'admin',
            'sent_to' => 'seller',
            'seller_id' => $insurance->seller_id,
            'title' => translate('Seller_Order_Insurance_Decision'),
            'description' => translate('seller_order_insurance_decision_updated') . ' #' . $insurance->order_id . ' - ' . translate($decision->action),
            'notification_count' => 1,
            'status' => 1,
        ]);
    }

    private function notifySellerPaymentResult(SellerOrderInsurance $insurance, string $result): void
    {
        if (!Schema::hasTable('notifications')) return;
        Notification::query()->create([
            'sent_by' => 'admin', 'sent_to' => 'seller', 'seller_id' => $insurance->seller_id,
            'title' => translate('Seller_Order_Insurance_Decision'),
            'description' => translate($result === 'approved'
                ? 'seller_order_insurance_payment_approved_details_unlocked'
                : 'seller_order_insurance_payment_rejected_retry'),
            'notification_count' => 1, 'status' => 1,
        ]);
    }

    private function assertNotUnderInvestigation(SellerOrderInsurance $insurance): void
    {
        if (! Schema::hasColumn('orders', 'commerce_flow_status')) {
            return;
        }
        if ($insurance->order()->where('commerce_flow_status', \App\Support\Commerce\OrderCommerceState::UNDER_INVESTIGATION)->exists()) {
            throw new DomainException('order_is_frozen_for_admin_investigation');
        }
    }
}
