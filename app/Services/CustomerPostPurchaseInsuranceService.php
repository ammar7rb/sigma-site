<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OrderInsurance;
use App\Models\SupportTicket;
use App\Events\OrderStatusEvent;
use App\Support\Commerce\OrderCommerceState;
use App\Utils\OrderManager;
use DomainException;
use Illuminate\Support\Facades\DB;

class CustomerPostPurchaseInsuranceService
{
    public function __construct(
        private readonly OrderCommerceFeatureService $features,
        private readonly OrderInsuranceService $insuranceService,
        private readonly CustomerInsuranceBalanceService $insuranceBalance,
        private readonly OrderCommerceContractService $contract,
    ) {
    }

    public function enabled(): bool
    {
        return $this->features->enabled('customer_insurance_after_purchase');
    }

    public function paymentDeadlineHours(): int
    {
        return max(1, min(720, (int) ($this->setting('customer_order_insurance_payment_deadline_hours')
            ?? config('order_commerce.customer_insurance.payment_deadline_hours', 72))));
    }

    public function purchaseRefundDelayDays(): int
    {
        return max(0, min(365, (int) ($this->setting('customer_purchase_refund_delay_days')
            ?? config('order_commerce.customer_insurance.purchase_refund_delay_days', 7))));
    }

    /**
     * Called immediately after a new order is stored. The purchase invoice is
     * already final here and never includes the insurance amount.
     */
    public function initializeNewOrder(Order $order, ?array $calculation = null): ?OrderInsurance
    {
        if (! $this->enabled() || $order->is_guest || ! $order->customer_id) {
            return null;
        }

        $order->forceFill([
            'commerce_flow_version' => config('order_commerce.new_flow_version', 'admin-gated-v2'),
            'commerce_flow_status' => OrderCommerceState::PURCHASE_PAYMENT_PENDING,
            'commerce_flow_status_updated_at' => now(),
            'admin_order_review_status' => 'waiting_customer_insurance',
            'order_status' => 'pending',
        ])->save();

        if ($order->payment_status !== 'paid') {
            return null;
        }

        return $this->activateAfterPurchasePayment($order, $calculation);
    }

    public function activateAfterPurchasePayment(Order $order, ?array $calculation = null): ?OrderInsurance
    {
        if (! $this->enabled() || $order->is_guest || ! $order->customer_id) {
            return null;
        }

        return DB::transaction(function () use ($order, $calculation): ?OrderInsurance {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->payment_status !== 'paid') {
                throw new DomainException('purchase_payment_must_be_paid_before_insurance');
            }

            $existing = OrderInsurance::query()->where('order_id', $locked->id)->first();
            if ($existing) {
                return $existing;
            }

            $calculation ??= $this->insuranceService->calculate(
                max(0, (float) $locked->order_amount),
                (int) $locked->customer_id,
            );

            if (! ($calculation['applicable'] ?? false) || (float) ($calculation['amount'] ?? 0) <= 0) {
                $locked->forceFill([
                    'commerce_flow_status' => OrderCommerceState::PENDING_ADMIN_REVIEW,
                    'commerce_flow_status_updated_at' => now(),
                    'admin_order_review_status' => 'pending',
                ])->save();
                return null;
            }

            $insurance = $this->insuranceService->createForOrder(
                orderId: (int) $locked->id,
                customerId: (int) $locked->customer_id,
                calculation: $calculation,
                paymentMethod: 'pending_selection',
                paymentStatus: 'unpaid',
            );
            $insurance->forceFill([
                'payment_due_at' => now()->addHours($this->paymentDeadlineHours()),
                'balance_use_policy' => 'insurance_only',
                'purchase_refund_status' => 'not_requested',
                'metadata' => array_merge($insurance->metadata ?? [], [
                    'flow' => 'post_purchase_insurance',
                    'purchase_payment_method' => $locked->payment_method,
                    'purchase_transaction_reference' => $locked->transaction_ref,
                    'purchase_amount' => (float) $locked->order_amount,
                    'payment_deadline_hours' => $this->paymentDeadlineHours(),
                    'purchase_refund_delay_days' => $this->purchaseRefundDelayDays(),
                ]),
            ])->save();

            $locked->forceFill([
                'commerce_flow_status' => OrderCommerceState::CUSTOMER_INSURANCE_PENDING,
                'commerce_flow_status_updated_at' => now(),
                'admin_order_review_status' => 'waiting_customer_insurance',
                'operational_blocked_by' => 'customer_insurance',
                'operational_block_reason' => 'customer_insurance_payment_required',
            ])->save();

            event(new OrderStatusEvent(
                key: 'customer_insurance_payment_required',
                type: 'customer',
                order: $locked->fresh(),
            ));

            return $insurance->fresh();
        });
    }

    public function payFromInsuranceBalance(OrderInsurance $insurance): OrderInsurance
    {
        $this->assertPayable($insurance);
        $this->insuranceBalance->debitForInsurance(
            $insurance,
            'customer-order-insurance-' . $insurance->id . '-balance-payment',
        );

        return $this->markPaid($insurance, 'customer_insurance_balance', 'insurance-wallet-' . $insurance->id);
    }

    public function markPaid(OrderInsurance $insurance, string $method, ?string $reference = null, ?int $adminId = null, ?string $note = null): OrderInsurance
    {
        return DB::transaction(function () use ($insurance, $method, $reference, $adminId, $note): OrderInsurance {
            $locked = OrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($locked->payment_status === 'paid') {
                return $locked;
            }
            $this->assertPayable($locked, allowPendingReview: true);

            $metadata = $locked->metadata ?? [];
            $metadata['insurance_payment_reference'] = $reference;
            $metadata['insurance_payment_method'] = $method;
            $locked->forceFill(['payment_method' => $method, 'metadata' => $metadata])->save();
            $paid = $this->insuranceService->markPaid($locked, $adminId, $note);
            $paid->forceFill(['payment_completed_at' => now()])->save();

            Order::query()->whereKey($paid->order_id)->update([
                'commerce_flow_status' => OrderCommerceState::PENDING_ADMIN_REVIEW,
                'commerce_flow_status_updated_at' => now(),
                'admin_order_review_status' => 'pending',
                'operational_blocked_by' => 'admin_review',
                'operational_block_reason' => 'waiting_admin_order_review',
            ]);
            event(new OrderStatusEvent(
                key: 'customer_insurance_paid_waiting_admin_review',
                type: 'customer',
                order: Order::query()->find($paid->order_id),
            ));

            return $paid->fresh();
        });
    }

    public function attachPaymentRequest(OrderInsurance $insurance, string $paymentId): OrderInsurance
    {
        $this->assertPayable($insurance);
        $metadata = $insurance->metadata ?? [];
        $metadata['payment_request_id'] = $paymentId;
        $insurance->forceFill(['payment_method' => 'digital_payment', 'metadata' => $metadata])->save();
        return $insurance->fresh();
    }

    public function submitOfflinePayment(OrderInsurance $insurance, array $proof): OrderInsurance
    {
        $this->assertPayable($insurance);
        $metadata = $insurance->metadata ?? [];
        $metadata['offline_payment'] = $proof;
        $insurance->forceFill([
            'payment_method' => 'offline_payment',
            'status' => 'pending_review',
            'metadata' => $metadata,
        ])->save();
        Order::query()->whereKey($insurance->order_id)->update([
            'commerce_flow_status' => OrderCommerceState::CUSTOMER_INSURANCE_UNDER_REVIEW,
            'commerce_flow_status_updated_at' => now(),
            'operational_blocked_by' => 'customer_insurance_review',
            'operational_block_reason' => 'customer_insurance_offline_payment_under_review',
        ]);
        event(new OrderStatusEvent(
            key: 'customer_insurance_offline_payment_under_review',
            type: 'customer',
            order: Order::query()->find($insurance->order_id),
        ));
        return $insurance->fresh();
    }

    public function rejectOfflinePayment(OrderInsurance $insurance, string $reason, ?int $adminId = null): OrderInsurance
    {
        return DB::transaction(function () use ($insurance, $reason, $adminId): OrderInsurance {
            $locked = OrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($locked->payment_status === 'paid' || $locked->status !== 'pending_review') {
                throw new DomainException('customer_order_insurance_payment_is_not_available');
            }

            $metadata = $locked->metadata ?? [];
            $metadata['offline_payment_rejection'] = [
                'reason' => $reason,
                'admin_id' => $adminId,
                'rejected_at' => now()->toIso8601String(),
            ];
            $locked->forceFill([
                'status' => 'pending_payment',
                'payment_method' => 'pending_selection',
                'admin_id' => $adminId,
                'admin_note' => $reason,
                'metadata' => $metadata,
            ])->save();
            Order::query()->whereKey($locked->order_id)->update([
                'commerce_flow_status' => OrderCommerceState::CUSTOMER_INSURANCE_PENDING,
                'commerce_flow_status_updated_at' => now(),
                'operational_blocked_by' => 'customer_insurance',
                'operational_block_reason' => 'customer_insurance_offline_payment_rejected',
            ]);
            event(new OrderStatusEvent(
                key: 'customer_insurance_offline_payment_rejected',
                type: 'customer',
                order: Order::query()->find($locked->order_id),
            ));
            return $locked->fresh();
        });
    }

    public function openSupportTicket(OrderInsurance $insurance, ?string $message = null): SupportTicket
    {
        if ($insurance->support_ticket_id) {
            $existing = SupportTicket::query()
                ->whereKey($insurance->support_ticket_id)
                ->where('customer_id', $insurance->customer_id)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $ticket = SupportTicket::query()->create([
            'customer_id' => $insurance->customer_id,
            'subject' => translate('customer_order_insurance_support_subject') . ' #' . $insurance->order_id,
            'type' => 'payment',
            'purpose' => 'order_insurance',
            'priority' => 'medium',
            'description' => $message ?: translate('customer_order_insurance_support_default_message'),
            'status' => 'open',
            'review_status' => 'pending',
        ]);
        $insurance->forceFill(['support_ticket_id' => $ticket->id])->save();
        return $ticket;
    }

    public function decline(OrderInsurance $insurance, string $reason = 'customer_declined_insurance'): OrderInsurance
    {
        return $this->cancelAndScheduleRefund($insurance, 'declined', $reason);
    }

    public function schedulePurchaseRefundForInvestigation(OrderInsurance $insurance, string $reason): OrderInsurance
    {
        return DB::transaction(function () use ($insurance, $reason): OrderInsurance {
            $locked = OrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if (in_array($locked->purchase_refund_status, ['scheduled', 'completed'], true)) {
                return $locked;
            }

            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);
            if ($order->order_status !== 'canceled') {
                OrderManager::getStockUpdateOnOrderStatusChange($order, 'canceled');
            }
            $order->forceFill([
                'order_status' => 'canceled',
                'commerce_flow_status' => OrderCommerceState::PURCHASE_REFUND_PENDING,
                'commerce_flow_status_updated_at' => now(),
                'operational_blocked_by' => 'admin_investigation',
                'operational_block_reason' => $reason,
            ])->save();

            $metadata = $locked->metadata ?? [];
            $metadata['investigation_refund'] = ['reason' => $reason, 'scheduled_at' => now()->toIso8601String()];
            $locked->forceFill([
                'purchase_refund_status' => 'scheduled',
                'purchase_refund_due_at' => now()->addDays($this->purchaseRefundDelayDays()),
                'admin_note' => $reason,
                'metadata' => $metadata,
            ])->save();
            event(new OrderStatusEvent(key: 'customer_insurance_expired_refund_scheduled', type: 'customer', order: $order->fresh()));
            return $locked->fresh();
        });
    }

    public function expireDue(int $limit = 100): int
    {
        $this->sendDueReminders($limit);
        $count = 0;
        OrderInsurance::query()
            ->where('payment_status', 'unpaid')
            ->whereIn('status', ['pending_payment', 'pending_review'])
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now())
            ->orderBy('id')->limit($limit)->get()
            ->each(function (OrderInsurance $insurance) use (&$count): void {
                if ($insurance->status === 'pending_review') {
                    return;
                }
                $this->cancelAndScheduleRefund($insurance, 'expired', 'customer_insurance_payment_deadline_expired');
                $count++;
            });
        return $count;
    }

    public function releaseDuePurchaseRefunds(int $limit = 100): int
    {
        $count = 0;
        OrderInsurance::query()
            ->where('purchase_refund_status', 'scheduled')
            ->whereNotNull('purchase_refund_due_at')
            ->where('purchase_refund_due_at', '<=', now())
            ->orderBy('id')->limit($limit)->get()
            ->each(function (OrderInsurance $insurance) use (&$count): void {
                $released = DB::transaction(function () use ($insurance): bool {
                    $locked = OrderInsurance::query()->lockForUpdate()->find($insurance->id);
                    if (! $locked || $locked->purchase_refund_status !== 'scheduled') {
                        return false;
                    }
                    $reference = 'post-purchase-insurance-refund-order-' . $locked->order_id;
                    app(CustomerPurchaseWalletPolicyService::class)->creditPurchaseRefund(
                        (int) $locked->customer_id,
                        (float) $locked->order_amount,
                        $reference,
                    );
                    $locked->forceFill([
                        'purchase_refund_status' => 'completed',
                        'purchase_refund_completed_at' => now(),
                        'purchase_refund_reference' => $reference,
                    ])->save();
                    Order::query()->whereKey($locked->order_id)->update([
                        'commerce_flow_status' => OrderCommerceState::CANCELLED,
                        'commerce_flow_status_updated_at' => now(),
                        'operational_blocked_by' => null,
                        'operational_block_reason' => 'purchase_refund_completed',
                    ]);
                    event(new OrderStatusEvent(
                        key: 'customer_purchase_refund_completed',
                        type: 'customer',
                        order: Order::query()->find($locked->order_id),
                    ));
                    return true;
                });
                $count += $released ? 1 : 0;
            });
        return $count;
    }

    public function payload(Order $order): array
    {
        $order->loadMissing('insurance');
        return $this->contract->customerInsurancePayload($order, $order->insurance);
    }

    private function cancelAndScheduleRefund(OrderInsurance $insurance, string $status, string $reason): OrderInsurance
    {
        return DB::transaction(function () use ($insurance, $status, $reason): OrderInsurance {
            $locked = OrderInsurance::query()->lockForUpdate()->findOrFail($insurance->id);
            if ($locked->payment_status === 'paid') {
                throw new DomainException('paid_insurance_cannot_be_declined');
            }
            if (in_array($locked->purchase_refund_status, ['scheduled', 'completed'], true)) {
                return $locked;
            }

            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);
            if ($order->order_status !== 'canceled') {
                OrderManager::getStockUpdateOnOrderStatusChange($order, 'canceled');
                $order->forceFill([
                    'order_status' => 'canceled',
                    'commerce_flow_status' => OrderCommerceState::PURCHASE_REFUND_PENDING,
                    'commerce_flow_status_updated_at' => now(),
                    'operational_blocked_by' => 'customer_insurance',
                    'operational_block_reason' => $reason,
                ])->save();
            }

            $locked->forceFill([
                'status' => $status,
                'purchase_refund_status' => 'scheduled',
                'purchase_refund_due_at' => now()->addDays($this->purchaseRefundDelayDays()),
                'admin_note' => $reason,
            ])->save();
            event(new OrderStatusEvent(
                key: 'customer_insurance_expired_refund_scheduled',
                type: 'customer',
                order: $order->fresh(),
            ));
            return $locked->fresh();
        });
    }

    private function sendDueReminders(int $limit): int
    {
        $sent = 0;
        OrderInsurance::query()
            ->where('payment_status', 'unpaid')
            ->where('status', 'pending_payment')
            ->whereBetween('payment_due_at', [now(), now()->addHours(24)])
            ->orderBy('id')->limit($limit)->get()
            ->each(function (OrderInsurance $insurance) use (&$sent): void {
                $metadata = $insurance->metadata ?? [];
                if (! empty($metadata['deadline_reminder_sent_at'])) {
                    return;
                }
                $metadata['deadline_reminder_sent_at'] = now()->toIso8601String();
                $insurance->forceFill(['metadata' => $metadata])->save();
                event(new OrderStatusEvent(
                    key: 'customer_insurance_payment_deadline_reminder',
                    type: 'customer',
                    order: $insurance->order,
                ));
                $sent++;
            });
        return $sent;
    }

    private function assertPayable(OrderInsurance $insurance, bool $allowPendingReview = false): void
    {
        if ($insurance->order()->where('commerce_flow_status', OrderCommerceState::UNDER_INVESTIGATION)->exists()) {
            throw new DomainException('order_is_frozen_for_admin_investigation');
        }
        if ($insurance->payment_status === 'paid') {
            throw new DomainException('customer_order_insurance_already_paid');
        }
        $payableStatuses = $allowPendingReview
            ? ['pending_payment', 'pending_review']
            : ['pending_payment'];
        if (! in_array($insurance->status, $payableStatuses, true)) {
            throw new DomainException('customer_order_insurance_payment_is_not_available');
        }
        if ($insurance->payment_due_at && $insurance->payment_due_at->isPast() && $insurance->status !== 'pending_review') {
            throw new DomainException('customer_order_insurance_payment_deadline_expired');
        }
    }

    private function setting(string $type): mixed
    {
        return BusinessSetting::query()->where('type', $type)->value('value');
    }
}
