<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderAdminGateDecision;
use App\Models\Seller;
use App\Models\SupportTicket;
use App\Support\Commerce\OrderCommerceState;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owns the seller's explicit response to an administrator-assigned shipping
 * offer.  A seller can never start fulfilment before accepting that offer.
 */
class SellerShippingResponseService
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const OVERDUE = 'overdue';

    public function startResponseWindow(Order $order): void
    {
        if (! $this->isManagedOrder($order) || ($order->shipping_assignment_status ?? 'pending') !== 'assigned') {
            return;
        }

        $order->forceFill([
            'seller_shipping_response_status' => self::PENDING,
            'seller_shipping_response_due_at' => now()->addDays($this->dueDays()),
            'seller_shipping_response_at' => null,
            'seller_shipping_rejection_reason' => null,
            // A new commercial offer replaces the previous escalation.
            'seller_shipping_support_ticket_id' => null,
            'shipping_workflow_status' => 'awaiting_seller_shipping_response',
            'operational_blocked_by' => 'seller_shipping_response',
            'operational_block_reason' => 'seller_must_accept_or_reject_shipping_assignment',
            'operational_status_updated_at' => now(),
        ])->save();
    }

    public function accept(Order $order, Seller $seller, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $seller, $note): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertSellerCanRespond($locked, $seller);
            $this->assertPending($locked);
            $before = $locked->commerce_flow_status;

            $locked->forceFill([
                'seller_shipping_response_status' => self::ACCEPTED,
                'seller_shipping_response_at' => now(),
                'seller_shipping_rejection_reason' => null,
                'shipping_workflow_status' => 'seller_preparing',
                'commerce_flow_status' => OrderCommerceState::FULFILLMENT_IN_PROGRESS,
                'commerce_flow_status_updated_at' => now(),
                'operational_blocked_by' => null,
                'operational_block_reason' => null,
                'operational_status_updated_at' => now(),
                'order_status' => in_array($locked->order_status, ['pending', 'confirmed'], true) ? 'processing' : $locked->order_status,
            ])->save();

            $this->recordDecision($locked, 'seller_shipping_accepted', $before, OrderCommerceState::FULFILLMENT_IN_PROGRESS, $note);
            $this->notifyAdmin($locked, 'seller_accepted_shipping_assignment');

            return $locked->fresh();
        });
    }

    public function reject(Order $order, Seller $seller, string $reason): Order
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('seller_shipping_rejection_reason_required');
        }

        return DB::transaction(function () use ($order, $seller, $reason): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertSellerCanRespond($locked, $seller);
            $this->assertPending($locked);
            $before = $locked->commerce_flow_status;

            $ticket = $this->openAdminTicket($locked, 'seller_shipping_rejected', $reason);
            $locked->forceFill([
                'seller_shipping_response_status' => self::REJECTED,
                'seller_shipping_response_at' => now(),
                'seller_shipping_rejection_reason' => $reason,
                'seller_shipping_support_ticket_id' => $ticket->id,
                'shipping_workflow_status' => 'seller_shipping_rejected',
                'commerce_flow_status' => OrderCommerceState::PENDING_ADMIN_REVIEW,
                'commerce_flow_status_updated_at' => now(),
                'admin_order_review_status' => 'seller_shipping_rejected',
                'operational_blocked_by' => 'seller_shipping_response',
                'operational_block_reason' => 'seller_rejected_shipping_assignment',
                'operational_status_updated_at' => now(),
            ])->save();

            $this->recordDecision($locked, 'seller_shipping_rejected', $before, OrderCommerceState::PENDING_ADMIN_REVIEW, $reason);
            $this->notifyAdmin($locked, 'seller_rejected_shipping_assignment');

            return $locked->fresh();
        });
    }

    public function monitorOverdueResponses(?int $limit = null): int
    {
        if (! Schema::hasColumn('orders', 'seller_shipping_response_due_at')) {
            return 0;
        }

        $query = Order::query()
            ->whereIn('commerce_flow_version', [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION])
            ->where('seller_shipping_response_status', self::PENDING)
            ->whereNotNull('seller_shipping_response_due_at')
            ->where('seller_shipping_response_due_at', '<=', now())
            ->orderBy('id');
        if ($limit) {
            $query->limit($limit);
        }

        $count = 0;
        foreach ($query->get() as $candidate) {
            $done = DB::transaction(function () use ($candidate): bool {
                $locked = Order::query()->lockForUpdate()->find($candidate->id);
                if (! $locked || $locked->seller_shipping_response_status !== self::PENDING || ! $locked->seller_shipping_response_due_at?->isPast()) {
                    return false;
                }
                $before = $locked->commerce_flow_status;
                $ticket = $this->openAdminTicket($locked, 'seller_shipping_response_overdue', 'Seller did not respond within the shipping response period.');
                $locked->forceFill([
                    'seller_shipping_response_status' => self::OVERDUE,
                    'seller_shipping_support_ticket_id' => $ticket->id,
                    'shipping_workflow_status' => 'seller_shipping_response_overdue',
                    'commerce_flow_status' => OrderCommerceState::PENDING_ADMIN_REVIEW,
                    'commerce_flow_status_updated_at' => now(),
                    'admin_order_review_status' => 'seller_shipping_response_overdue',
                    'operational_blocked_by' => 'seller_shipping_response',
                    'operational_block_reason' => 'seller_shipping_response_overdue',
                    'operational_status_updated_at' => now(),
                ])->save();
                $this->recordDecision($locked, 'seller_shipping_response_overdue', $before, OrderCommerceState::PENDING_ADMIN_REVIEW, 'Automatic three-day escalation.');
                $this->notifyAdmin($locked, 'seller_shipping_response_overdue');
                return true;
            });
            $count += $done ? 1 : 0;
        }

        return $count;
    }

    /**
     * Lets the administrator follow up with a seller who has not yet accepted
     * or rejected the shipping terms. The notification is deliberately kept
     * on the seller channel so the seller can see it from their dashboard.
     */
    public function sendFollowUp(Order $order, string $message, ?int $adminId = null): Order
    {
        $message = trim($message);
        if ($message === '') {
            throw new DomainException('message_is_required');
        }

        return DB::transaction(function () use ($order, $message, $adminId): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $this->isManagedOrder($locked)
                || $locked->seller_is !== 'seller'
                || ! in_array($locked->seller_shipping_response_status, [self::PENDING, self::OVERDUE], true)) {
                throw new DomainException('seller_shipping_follow_up_not_available');
            }

            if (Schema::hasTable('notifications')) {
                Notification::query()->create([
                    'sent_by' => 'admin',
                    'sent_to' => 'seller',
                    'seller_id' => $locked->seller_id,
                    'title' => translate('seller_shipping_response_required'),
                    'description' => translate('order') . ' #' . $locked->id . ': ' . $message,
                    'notification_count' => 1,
                    'status' => 1,
                ]);
            }

            $before = (string) ($locked->commerce_flow_status ?? '');
            $this->recordDecision($locked, 'seller_shipping_follow_up_sent', $before, $before, $message);

            return $locked->fresh();
        });
    }

    private function assertSellerCanRespond(Order $order, Seller $seller): void
    {
        if (! $this->isManagedOrder($order) || $order->seller_is !== 'seller' || (int) $order->seller_id !== (int) $seller->id) {
            throw new DomainException('seller_shipping_response_order_not_found');
        }
        if (($order->shipping_assignment_status ?? 'pending') !== 'assigned') {
            throw new DomainException('shipping_decision_is_required_before_seller_release');
        }
        if (! in_array($order->commerce_flow_status, [OrderCommerceState::RELEASED_TO_SELLER, OrderCommerceState::FULFILLMENT_IN_PROGRESS], true)) {
            throw new DomainException('seller_order_insurance_payment_required');
        }
    }

    private function assertPending(Order $order): void
    {
        if ($order->seller_shipping_response_status !== self::PENDING) {
            throw new DomainException('seller_shipping_response_is_not_pending');
        }
    }

    private function isManagedOrder(Order $order): bool
    {
        return in_array($order->commerce_flow_version, [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION], true);
    }

    private function dueDays(): int
    {
        $value = BusinessSetting::query()->where('type', 'seller_shipping_response_due_days')->value('value');
        return min(30, max(1, is_numeric($value) ? (int) $value : 3));
    }

    private function openAdminTicket(Order $order, string $purpose, string $description): SupportTicket
    {
        return SupportTicket::query()->firstOrCreate([
            'purpose' => $purpose,
            'subject' => 'Order #' . $order->id,
            'status' => 'open',
        ], [
            'customer_id' => null,
            'type' => 'seller_order_shipping',
            'priority' => 'high',
            'description' => $description,
        ]);
    }

    private function recordDecision(Order $order, string $action, string $before, string $after, ?string $note): void
    {
        OrderAdminGateDecision::query()->create([
            'order_id' => $order->id,
            'admin_id' => null,
            'action' => $action,
            'status_before' => $before,
            'status_after' => $after,
            'note' => $note,
            'snapshot' => ['seller_shipping_response_status' => $order->seller_shipping_response_status],
        ]);
    }

    private function notifyAdmin(Order $order, string $message): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }
        Notification::query()->create([
            'sent_by' => 'seller',
            'sent_to' => 'admin',
            'seller_id' => $order->seller_id,
            'title' => translate('Order_shipping_response'),
            'description' => translate($message) . ' #' . $order->id,
            'notification_count' => 1,
            'status' => 1,
        ]);
    }
}
