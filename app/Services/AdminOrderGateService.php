<?php

namespace App\Services;

use App\Events\OrderStatusEvent;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderAdminGateDecision;
use App\Models\OrderAdminPurchaseRefund;
use App\Models\OrderInsurance;
use App\Models\OrderRiskInvestigation;
use App\Models\OrderRiskInvestigationMessage;
use App\Models\SellerOrderInsurance;
use App\Models\SupportTicket;
use App\Support\Commerce\OrderCommerceState;
use App\Utils\OrderManager;
use DomainException;
use Illuminate\Support\Facades\DB;

class AdminOrderGateService
{
    public function __construct(
        private readonly SellerOrderInsuranceService $sellerInsurance,
        private readonly OrderShippingDecisionService $shipping,
        private readonly InsuranceBalanceGovernanceService $governance,
        private readonly CustomerPostPurchaseInsuranceService $customerInsuranceFlow,
        private readonly CustomerPurchaseWalletPolicyService $purchaseWallet,
    ) {}

    /** @return array<string, mixed> */
    public function sellerInsuranceSuggestion(Order $order): array
    {
        if ($order->seller_is !== 'seller' || ! $order->seller) {
            return ['applicable' => false, 'amount' => 0, 'reason' => 'order_has_no_external_seller'];
        }
        return $this->sellerInsurance->preview($order, $order->seller);
    }

    public function saveAssignment(Order $order, array $data, ?int $adminId): OrderAdminGateDecision
    {
        return DB::transaction(function () use ($order, $data, $adminId): OrderAdminGateDecision {
            $locked = Order::query()->with(['insurance', 'seller', 'sellerOrderInsurance'])->lockForUpdate()->findOrFail($order->id);
            $this->assertGateReadyForAssignment($locked);
            $before = $locked->commerce_flow_status;

            $locked->forceFill([
                'commerce_flow_status' => OrderCommerceState::ADMIN_ASSIGNMENT_IN_PROGRESS,
                'commerce_flow_status_updated_at' => now(),
                'admin_order_review_status' => 'assignment_in_progress',
                'operational_blocked_by' => 'admin_review',
                'operational_block_reason' => 'admin_is_assigning_seller_insurance_and_shipping',
            ])->save();

            // A seller can reject a shipping offer after already paying the
            // deposit.  In that case the deposit is immutable; only the
            // shipping terms are revised and offered again.
            $insurance = $locked->sellerOrderInsurance;
            if (! $insurance || ! in_array($insurance->status, [SellerOrderInsurance::STATUS_PAID, SellerOrderInsurance::STATUS_WAIVED], true)) {
                $insurance = $this->sellerInsurance->createFromAdminDecision(
                    $locked, $locked->seller,
                    [
                        'mode' => $data['seller_insurance_mode'],
                        'calculation_type' => $data['seller_insurance_calculation_type'] ?? null,
                        'calculation_value' => $data['seller_insurance_calculation_value'] ?? null,
                        'reason' => $data['seller_insurance_override_reason'] ?? null,
                    ],
                    $adminId,
                );
            }
            $shipping = $this->shipping->assign($locked, [
                'mode' => $data['shipping_mode'],
                'shipping_method_id' => $data['shipping_method_id'] ?? null,
                'customer_cost' => $data['shipping_customer_cost'] ?? $locked->shipping_cost,
                'seller_cost' => $data['shipping_seller_cost'] ?? null,
                'seller_entitlement' => $data['shipping_seller_entitlement'] ?? ($data['shipping_mode'] === 'seller_shipping' ? ($data['shipping_seller_cost'] ?? null) : null),
                'service_name' => $data['shipping_service_name'] ?? null,
                'tracking_number' => $data['shipping_tracking_number'] ?? null,
                'expected_delivery_date' => $data['shipping_expected_delivery_date'] ?? null,
                'instructions' => $data['shipping_instructions'] ?? null,
                'note' => $data['decision_note'],
            ], $adminId);

            return OrderAdminGateDecision::query()->create([
                'order_id' => $locked->id, 'admin_id' => $adminId, 'action' => 'assignment_saved',
                'status_before' => $before, 'status_after' => OrderCommerceState::ADMIN_ASSIGNMENT_IN_PROGRESS,
                'seller_order_insurance_id' => $insurance->id, 'shipping_decision_id' => $shipping->id,
                'seller_insurance_source' => $insurance->amount_source,
                'seller_insurance_calculation_type' => $insurance->calculation_type,
                'seller_insurance_calculation_value' => $insurance->calculation_value,
                'seller_insurance_amount' => $insurance->amount,
                'override_reason' => $insurance->admin_decision_reason,
                'note' => $data['decision_note'],
                'snapshot' => $this->decisionSnapshot($locked->fresh(), $insurance->fresh(), $shipping->fresh()),
            ]);
        });
    }

    public function releaseToSeller(Order $order, string $note, ?int $adminId): OrderAdminGateDecision
    {
        return DB::transaction(function () use ($order, $note, $adminId): OrderAdminGateDecision {
            $locked = Order::query()->with(['insurance', 'sellerOrderInsurance'])->lockForUpdate()->findOrFail($order->id);
            $this->assertCustomerInsuranceAccepted($locked);
            if ($locked->commerce_flow_status !== OrderCommerceState::ADMIN_ASSIGNMENT_IN_PROGRESS) {
                throw new DomainException('admin_order_assignment_must_be_completed_first');
            }
            $insurance = $locked->sellerOrderInsurance;
            if (! $insurance || ! in_array($insurance->status, [SellerOrderInsurance::STATUS_PENDING_PAYMENT, SellerOrderInsurance::STATUS_PAID, SellerOrderInsurance::STATUS_WAIVED], true)) {
                throw new DomainException('seller_order_insurance_admin_decision_is_required');
            }
            $shipping = $locked->shippingDecisions()->latest('id')->first();
            if (($locked->shipping_assignment_status ?? 'pending') !== 'assigned' || ! $shipping) {
                throw new DomainException('shipping_decision_is_required_before_seller_release');
            }

            $before = $locked->commerce_flow_status;
            app(OrderCommerceContractService::class)->restrictedAccessToken($locked);
            $after = in_array($insurance->status, [SellerOrderInsurance::STATUS_PAID, SellerOrderInsurance::STATUS_WAIVED], true)
                ? OrderCommerceState::RELEASED_TO_SELLER
                : OrderCommerceState::SELLER_INSURANCE_PENDING;
            $locked->forceFill([
                'commerce_flow_status' => $after, 'commerce_flow_status_updated_at' => now(),
                'admin_order_review_status' => 'approved', 'admin_order_reviewed_by' => $adminId,
                'admin_order_reviewed_at' => now(), 'admin_order_review_note' => $note,
                'operational_blocked_by' => $after === OrderCommerceState::SELLER_INSURANCE_PENDING ? 'seller_insurance' : null,
                'operational_block_reason' => $after === OrderCommerceState::SELLER_INSURANCE_PENDING ? 'seller_order_insurance_payment_required' : null,
            ])->save();

            // The v3 customer invoice is now completed by the admin gate, not
            // by a direct customer-to-seller release.  A waived seller
            // insurance therefore still needs the seller to accept the
            // assigned shipping terms before fulfilment can begin.
            if ($after === OrderCommerceState::RELEASED_TO_SELLER) {
                if ($locked->commerce_flow_version === PostPurchaseInvoiceService::CONTRACT_VERSION) {
                    $locked->forceFill([
                        'post_purchase_status' => PostPurchaseInvoiceService::ORDER_STATUS_RELEASED,
                        'order_status' => 'confirmed',
                    ])->save();
                }
                app(SellerShippingResponseService::class)->startResponseWindow($locked->fresh());
            }

            $decision = OrderAdminGateDecision::query()->create([
                'order_id' => $locked->id, 'admin_id' => $adminId, 'action' => 'released_to_seller',
                'status_before' => $before, 'status_after' => $after,
                'seller_order_insurance_id' => $insurance->id, 'shipping_decision_id' => $shipping->id,
                'seller_insurance_source' => $insurance->amount_source,
                'seller_insurance_calculation_type' => $insurance->calculation_type,
                'seller_insurance_calculation_value' => $insurance->calculation_value,
                'seller_insurance_amount' => $insurance->amount, 'override_reason' => $insurance->admin_decision_reason,
                'note' => $note, 'snapshot' => $this->decisionSnapshot($locked->fresh(), $insurance, $shipping),
            ]);
            event(new OrderStatusEvent(key: 'admin_order_released_to_seller', type: 'seller', order: $locked->fresh()));
            Notification::query()->firstOrCreate([
                'sent_to' => 'seller',
                'seller_id' => $locked->seller_id,
                'title' => translate('new_approved_order'),
                'description' => translate('order_released_to_seller_notification').' #'.$locked->id,
            ], [
                'sent_by' => 'admin', 'notification_count' => 1, 'status' => 1,
            ]);
            return $decision;
        });
    }

    public function openInvestigation(Order $order, array $data, ?int $adminId): OrderRiskInvestigation
    {
        return DB::transaction(function () use ($order, $data, $adminId): OrderRiskInvestigation {
            $locked = Order::query()->with(['insurance', 'sellerOrderInsurance'])->lockForUpdate()->findOrFail($order->id);
            if ($existing = OrderRiskInvestigation::query()->where('order_id', $locked->id)->where('status', OrderRiskInvestigation::STATUS_OPEN)->first()) {
                return $existing;
            }
            $previous = $locked->commerce_flow_status;
            $investigation = OrderRiskInvestigation::query()->create([
                'order_id' => $locked->id, 'opened_by_admin_id' => $adminId, 'status' => OrderRiskInvestigation::STATUS_OPEN,
                'risk_level' => $data['risk_level'], 'reason' => $data['reason'], 'evidence' => $data['evidence'] ?? null,
                'customer_insurance_frozen' => (bool) $locked->insurance,
                'seller_insurance_frozen' => (bool) $locked->sellerOrderInsurance,
                'previous_flow_status' => $previous, 'opened_at' => now(),
            ]);
            $locked->forceFill([
                'commerce_flow_status' => OrderCommerceState::UNDER_INVESTIGATION,
                'commerce_flow_status_updated_at' => now(), 'admin_order_review_status' => 'under_investigation',
                'operational_blocked_by' => 'admin_investigation', 'operational_block_reason' => $data['reason'],
            ])->save();
            OrderAdminGateDecision::query()->create([
                'order_id' => $locked->id, 'admin_id' => $adminId, 'action' => 'investigation_opened',
                'status_before' => $previous, 'status_after' => OrderCommerceState::UNDER_INVESTIGATION,
                'note' => $data['reason'], 'snapshot' => ['risk_level' => $data['risk_level'], 'investigation_id' => $investigation->id],
            ]);
            return $investigation;
        });
    }

    public function sendInvestigationMessage(OrderRiskInvestigation $investigation, string $recipientType, string $message, ?int $adminId): OrderRiskInvestigationMessage
    {
        if (! in_array($recipientType, ['customer', 'seller'], true) || $investigation->status !== OrderRiskInvestigation::STATUS_OPEN) {
            throw new DomainException('invalid_order_investigation_message');
        }
        $order = $investigation->order;
        $recipientId = $recipientType === 'customer' ? (int) $order->customer_id : (int) $order->seller_id;
        $ticketId = null;
        if ($recipientType === 'customer') {
            $existingTicketId = $investigation->messages()->where('recipient_type', 'customer')->whereNotNull('support_ticket_id')->value('support_ticket_id');
            $ticket = $existingTicketId ? SupportTicket::query()->find($existingTicketId) : null;
            if (! $ticket) {
                $ticket = SupportTicket::query()->create([
                    'customer_id' => $recipientId, 'subject' => translate('order_investigation') . ' #' . $order->id,
                    'type' => 'complaint', 'purpose' => 'order_investigation', 'priority' => 'high',
                    'description' => $message, 'status' => 'open', 'review_status' => 'pending',
                ]);
            } else {
                $ticket->update(['reply' => trim(($ticket->reply ? $ticket->reply . "\n\n" : '') . $message)]);
            }
            $ticketId = $ticket->id;
        } else {
            Notification::query()->create([
                'sent_by' => 'admin', 'sent_to' => 'seller', 'seller_id' => $recipientId,
                'title' => translate('order_investigation'), 'description' => $message,
                'notification_count' => 1, 'status' => 1,
            ]);
        }

        return OrderRiskInvestigationMessage::query()->create([
            'investigation_id' => $investigation->id, 'admin_id' => $adminId,
            'recipient_type' => $recipientType, 'recipient_id' => $recipientId,
            'message' => $message, 'support_ticket_id' => $ticketId,
        ]);
    }

    public function resolveInvestigation(OrderRiskInvestigation $investigation, string $action, string $note, ?int $adminId): OrderRiskInvestigation
    {
        $allowed = ['resume', 'cancel_and_refund', 'confiscate_customer_and_refund', 'confiscate_seller_and_resume', 'confiscate_both_and_refund'];
        if (! in_array($action, $allowed, true) || trim($note) === '') throw new DomainException('invalid_order_investigation_resolution');

        return DB::transaction(function () use ($investigation, $action, $note, $adminId): OrderRiskInvestigation {
            $locked = OrderRiskInvestigation::query()->with(['order.insurance', 'order.sellerOrderInsurance'])->lockForUpdate()->findOrFail($investigation->id);
            if ($locked->status !== OrderRiskInvestigation::STATUS_OPEN) return $locked;
            $order = $locked->order;

            if (in_array($action, ['confiscate_customer_and_refund', 'confiscate_both_and_refund'], true)) {
                $customerInsurance = $order->insurance;
                if (! $customerInsurance) throw new DomainException('customer_order_insurance_not_found');
                $remaining = round((float) $customerInsurance->amount - (float) $customerInsurance->confiscated_amount, 2);
                if ($remaining > 0) $this->governance->confiscateCustomerInsurance($customerInsurance, $remaining, 'investigation-'.$locked->id.'-customer-confiscation', $note, $adminId, ['investigation_id' => $locked->id]);
            }
            if (in_array($action, ['confiscate_seller_and_resume', 'confiscate_both_and_refund'], true)) {
                $sellerInsurance = $order->sellerOrderInsurance;
                if (! $sellerInsurance) throw new DomainException('seller_order_insurance_not_found');
                $remaining = round((float) $sellerInsurance->amount - (float) $sellerInsurance->confiscated_amount, 2);
                if ($remaining > 0) $this->governance->confiscateSellerInsurance($sellerInsurance, $remaining, 'investigation-'.$locked->id.'-seller-confiscation', $note, $adminId, ['investigation_id' => $locked->id]);
            }

            if (in_array($action, ['cancel_and_refund', 'confiscate_customer_and_refund', 'confiscate_both_and_refund'], true)) {
                $this->schedulePurchaseRefund($order, $note, $adminId);
            } else {
                $restore = $locked->previous_flow_status ?: OrderCommerceState::PENDING_ADMIN_REVIEW;
                $order->forceFill([
                    'commerce_flow_status' => $restore, 'commerce_flow_status_updated_at' => now(),
                    'admin_order_review_status' => $restore === OrderCommerceState::PENDING_ADMIN_REVIEW ? 'pending' : 'assignment_in_progress',
                    'operational_blocked_by' => in_array($restore, [OrderCommerceState::PENDING_ADMIN_REVIEW, OrderCommerceState::ADMIN_ASSIGNMENT_IN_PROGRESS], true) ? 'admin_review' : null,
                    'operational_block_reason' => 'investigation_resolved',
                ])->save();
            }

            $locked->forceFill([
                'status' => OrderRiskInvestigation::STATUS_RESOLVED, 'resolved_by_admin_id' => $adminId,
                'resolution_action' => $action, 'resolution_note' => $note, 'resolved_at' => now(),
                'customer_insurance_frozen' => false, 'seller_insurance_frozen' => false,
            ])->save();
            OrderAdminGateDecision::query()->create([
                'order_id' => $order->id, 'admin_id' => $adminId, 'action' => 'investigation_resolved',
                'status_before' => OrderCommerceState::UNDER_INVESTIGATION, 'status_after' => $order->fresh()->commerce_flow_status,
                'note' => $note, 'snapshot' => ['investigation_id' => $locked->id, 'resolution_action' => $action],
            ]);
            return $locked->fresh();
        });
    }

    public function releaseDueUninsuredRefunds(int $limit = 100): int
    {
        $count = 0;
        OrderAdminPurchaseRefund::query()->where('status', 'scheduled')->where('due_at', '<=', now())->orderBy('id')->limit($limit)->get()
            ->each(function (OrderAdminPurchaseRefund $refund) use (&$count): void {
                $done = DB::transaction(function () use ($refund): bool {
                    $locked = OrderAdminPurchaseRefund::query()->lockForUpdate()->find($refund->id);
                    if (! $locked || $locked->status !== 'scheduled') return false;
                    $reference = 'admin-investigation-refund-order-'.$locked->order_id;
                    $this->purchaseWallet->creditPurchaseRefund($locked->customer_id, $locked->amount, $reference);
                    $locked->update(['status' => 'completed', 'completed_at' => now(), 'reference' => $reference]);
                    Order::query()->whereKey($locked->order_id)->update(['commerce_flow_status' => OrderCommerceState::CANCELLED, 'commerce_flow_status_updated_at' => now(), 'operational_blocked_by' => null, 'operational_block_reason' => 'purchase_refund_completed']);
                    return true;
                });
                $count += $done ? 1 : 0;
            });
        return $count;
    }

    private function assertGateReadyForAssignment(Order $order): void
    {
        if ($order->seller_is !== 'seller' || ! $order->seller) throw new DomainException('admin_order_gate_requires_seller_order');
        if (! in_array($order->commerce_flow_status, [OrderCommerceState::PENDING_ADMIN_REVIEW, OrderCommerceState::ADMIN_ASSIGNMENT_IN_PROGRESS], true)) throw new DomainException('order_is_not_waiting_for_admin_review');
        // Administration may prepare terms while payment is reviewed.
        // releaseToSeller still requires all customer payments to be accepted.
        if ($order->riskInvestigations()->where('status', OrderRiskInvestigation::STATUS_OPEN)->exists()) throw new DomainException('order_is_frozen_for_admin_investigation');
    }

    private function assertCustomerInsuranceAccepted(Order $order): void
    {
        if ($order->payment_status !== 'paid') throw new DomainException('purchase_payment_must_be_paid_before_admin_review');
        if ($order->commerce_flow_version === PostPurchaseInvoiceService::CONTRACT_VERSION) {
            $invoice = $order->postPurchaseInvoice()->first();
            if (!$invoice && $order->post_purchase_status === PostPurchaseInvoiceService::ORDER_STATUS_AWAITING_ADMIN) return;
            if (! $invoice || $invoice->status !== \App\Models\PostPurchaseInvoice::STATUS_PAID) {
                throw new DomainException('customer_post_purchase_invoice_must_be_paid_before_admin_review');
            }
            return;
        }
        if ($order->insurance && $order->insurance->payment_status !== 'paid') throw new DomainException('customer_insurance_must_be_paid_before_admin_review');
    }

    private function schedulePurchaseRefund(Order $order, string $reason, ?int $adminId): void
    {
        if ($order->insurance) {
            $this->customerInsuranceFlow->schedulePurchaseRefundForInvestigation($order->insurance, $reason);
            return;
        }
        if ($order->order_status !== 'canceled') OrderManager::getStockUpdateOnOrderStatusChange($order, 'canceled');
        $days = max(0, min(365, (int) (\App\Models\BusinessSetting::query()->where('type', 'customer_purchase_refund_delay_days')->value('value') ?? config('order_commerce.customer_insurance.purchase_refund_delay_days', 7))));
        OrderAdminPurchaseRefund::query()->firstOrCreate(['order_id' => $order->id], [
            'customer_id' => $order->customer_id, 'amount' => $order->order_amount, 'status' => 'scheduled',
            'due_at' => now()->addDays($days), 'reason' => $reason, 'admin_id' => $adminId,
        ]);
        $order->forceFill(['order_status' => 'canceled', 'commerce_flow_status' => OrderCommerceState::PURCHASE_REFUND_PENDING, 'commerce_flow_status_updated_at' => now(), 'operational_blocked_by' => 'admin_investigation', 'operational_block_reason' => $reason])->save();
    }

    /** @return array<string, mixed> */
    private function decisionSnapshot(Order $order, SellerOrderInsurance $insurance, $shipping): array
    {
        return [
            'order_amount' => (float) $order->order_amount,
            'customer_insurance' => $order->insurance ? ['id' => $order->insurance->id, 'amount' => (float) $order->insurance->amount, 'payment_status' => $order->insurance->payment_status] : null,
            'post_purchase_invoice' => $order->postPurchaseInvoice ? [
                'id' => $order->postPurchaseInvoice->id,
                'tax_amount' => (float) $order->postPurchaseInvoice->tax_amount,
                'insurance_amount' => (float) $order->postPurchaseInvoice->insurance_amount,
                'total_amount' => (float) $order->postPurchaseInvoice->total_amount,
                'paid_amount' => (float) $order->postPurchaseInvoice->paid_amount,
                'status' => $order->postPurchaseInvoice->status,
            ] : null,
            'seller_insurance' => ['id' => $insurance->id, 'amount' => (float) $insurance->amount, 'source' => $insurance->amount_source, 'status' => $insurance->status],
            'shipping' => ['id' => $shipping->id, 'mode' => $shipping->mode, 'customer_cost' => (float) ($shipping->customer_cost ?? 0), 'seller_cost' => (float) ($shipping->seller_cost ?? 0), 'tracking_number' => $shipping->tracking_number],
        ];
    }
}
