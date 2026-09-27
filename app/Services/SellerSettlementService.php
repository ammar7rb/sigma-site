<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\SellerSettlement;
use App\Models\SellerSettlementDispute;
use App\Models\RefundRequest;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SellerSettlementService
{
    public function __construct(private readonly SellerLedgerService $ledger) {}

    /**
     * Places the seller's delivery earning in the pending bucket exactly once.
     */
    public function holdDeliveredOrder(Order $order, float $amount, ?Carbon $manualDueAt = null): ?SellerSettlement
    {
        if ($amount <= 0 || $order->seller_is !== 'seller' || ! $order->seller_id) {
            return null;
        }

        return DB::transaction(function () use ($order, $amount, $manualDueAt) {
            $existing = SellerSettlement::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            // The order status controller invokes this before saving the new
            // delivered timestamp, so the transition time is the current time.
            $deliveredAt = now()->copy();
            $dueAt = $manualDueAt?->copy() ?? $this->calculateDueAt($deliveredAt);
            $timezone = $this->timezone();
            $preDueDays = $this->preDueAlertDays();
            $settlementPayload = [
                'seller_id' => (int) $order->seller_id,
                'order_id' => (int) $order->id,
                'amount' => $amount,
                'status' => SellerSettlement::STATUS_HELD,
                'delivered_at' => $deliveredAt,
                'dispute_until' => $dueAt,
                'due_at' => $dueAt,
                'timezone_snapshot' => $timezone,
                'pre_due_days_snapshot' => $preDueDays,
                'metadata' => [
                    'hold_days' => $this->holdDays(),
                    'working_days' => $this->workingDays(),
                    'holidays' => $this->holidays(),
                    'timezone' => $timezone,
                    'pre_due_alert_days' => $preDueDays,
                    'source' => 'order_delivered',
                ],
            ];
            $settlement = SellerSettlement::create(array_intersect_key($settlementPayload, array_flip(Schema::getColumnListing('seller_settlements'))));

            $this->ledger->post(
                sellerId: (int) $order->seller_id,
                eventType: 'seller_order_settlement_hold',
                groupKey: 'seller-settlement:' . $settlement->id . ':hold',
                movements: [[
                    'bucket' => SellerLedgerService::PENDING,
                    'direction' => 'credit',
                    'amount' => $amount,
                ]],
                referenceType: SellerSettlement::class,
                referenceId: (int) $settlement->id,
                metadata: ['order_id' => (int) $order->id],
            );

            $this->notifySeller($settlement, 'settlement_held');
            return $settlement;
        });
    }

    public function releaseDue(?Carbon $now = null): int
    {
        $now ??= now();
        $ids = SellerSettlement::query()
            ->where('status', SellerSettlement::STATUS_HELD)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->pluck('id');

        $released = 0;
        foreach ($ids as $id) {
            if ($this->release((int) $id, $now)) {
                $released++;
            }
        }
        return $released;
    }

    /** Notify about settlements approaching their due date and those now overdue. */
    public function sendDueAlerts(?Carbon $now = null): array
    {
        $now ??= now();
        $upcomingColumns = ['id', 'due_at'];
        if (Schema::hasColumn('seller_settlements', 'pre_due_days_snapshot')) $upcomingColumns[] = 'pre_due_days_snapshot';
        $upcoming = SellerSettlement::query()
            ->where('status', SellerSettlement::STATUS_HELD)
            ->whereNotNull('due_at')
            ->where('due_at', '>', $now)
            ->whereNull('pre_due_notified_at')
            ->get($upcomingColumns)
            ->filter(fn (SellerSettlement $item) => $item->due_at?->lte($now->copy()->addDays($item->pre_due_days_snapshot ?: 5)))
            ->pluck('id');
        $overdue = SellerSettlement::query()
            ->where('status', SellerSettlement::STATUS_HELD)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->whereNull('overdue_notified_at')
            ->pluck('id');

        $upcomingCount = 0;
        foreach ($upcoming as $id) {
            DB::transaction(function () use ($id, $now, &$upcomingCount): void {
                $model = SellerSettlement::query()->lockForUpdate()->find($id);
                if (! $model || $model->status !== SellerSettlement::STATUS_HELD || $model->pre_due_notified_at) {
                    return;
                }
                $this->notifySeller($model, 'settlement_upcoming');
                $model->update(['pre_due_notified_at' => $now]);
                $upcomingCount++;
            });
        }

        $overdueCount = 0;
        foreach ($overdue as $id) {
            DB::transaction(function () use ($id, $now, &$overdueCount): void {
                $model = SellerSettlement::query()->lockForUpdate()->find($id);
                if (! $model || $model->status !== SellerSettlement::STATUS_HELD || $model->overdue_notified_at) {
                    return;
                }
                $this->notifySeller($model, 'settlement_overdue');
                $model->update(['overdue_notified_at' => $now]);
                $overdueCount++;
            });
        }

        $escalatedCount = 0;
        if (! Schema::hasColumn('seller_settlements', 'escalated_at')) {
            return ['upcoming' => $upcomingCount, 'overdue' => $overdueCount];
        }
        $escalationDays = $this->overdueEscalationDays();
        $escalationIds = SellerSettlement::query()
            ->where('status', SellerSettlement::STATUS_HELD)
            ->whereNotNull('due_at')->whereNull('escalated_at')
            ->where('due_at', '<=', $now->copy()->subDays($escalationDays))
            ->pluck('id');
        foreach ($escalationIds as $id) {
            DB::transaction(function () use ($id, $now, &$escalatedCount): void {
                $model = SellerSettlement::query()->lockForUpdate()->find($id);
                if (! $model || $model->status !== SellerSettlement::STATUS_HELD || $model->escalated_at) return;
                $this->notifySeller($model, 'settlement_escalated');
                $model->update(['escalated_at' => $now]);
                $escalatedCount++;
            });
        }

        $result = ['upcoming' => $upcomingCount, 'overdue' => $overdueCount];
        if ($escalatedCount > 0) $result['escalated'] = $escalatedCount;
        return $result;
    }

    public function release(int|SellerSettlement $settlement, ?Carbon $now = null): bool
    {
        $now ??= now();
        return DB::transaction(function () use ($settlement, $now) {
            $model = SellerSettlement::query()->lockForUpdate()->find($settlement instanceof SellerSettlement ? $settlement->id : $settlement);
            if (! $model || ! in_array($model->status, [SellerSettlement::STATUS_HELD, SellerSettlement::STATUS_DISPUTED], true)) {
                return false;
            }
            if ($model->due_at && $model->due_at->isFuture()) {
                return false;
            }

            $this->ledger->post(
                sellerId: (int) $model->seller_id,
                eventType: 'seller_order_settlement_release',
                groupKey: 'seller-settlement:' . $model->id . ':release',
                movements: [
                    ['bucket' => SellerLedgerService::PENDING, 'direction' => 'debit', 'amount' => (float) $model->amount],
                    ['bucket' => SellerLedgerService::AVAILABLE, 'direction' => 'credit', 'amount' => (float) $model->amount],
                ],
                referenceType: SellerSettlement::class,
                referenceId: (int) $model->id,
            );
            $model->update(['status' => SellerSettlement::STATUS_RELEASED, 'released_at' => $now]);
            $this->notifySeller($model, 'settlement_released');
            return true;
        });
    }

    public function reverse(int|SellerSettlement $settlement, string $reason, ?int $adminId = null): bool
    {
        return DB::transaction(function () use ($settlement, $reason, $adminId) {
            $model = SellerSettlement::query()->lockForUpdate()->find($settlement instanceof SellerSettlement ? $settlement->id : $settlement);
            if (! $model || ! in_array($model->status, [SellerSettlement::STATUS_HELD, SellerSettlement::STATUS_DISPUTED, SellerSettlement::STATUS_RELEASED], true)) {
                return false;
            }
            $bucket = $model->status === SellerSettlement::STATUS_RELEASED
                ? SellerLedgerService::AVAILABLE
                : SellerLedgerService::PENDING;
            $this->ledger->post(
                sellerId: (int) $model->seller_id,
                eventType: 'seller_order_settlement_reverse',
                groupKey: 'seller-settlement:' . $model->id . ':reverse',
                movements: [['bucket' => $bucket, 'direction' => 'debit', 'amount' => (float) $model->amount]],
                referenceType: SellerSettlement::class,
                referenceId: (int) $model->id,
                metadata: ['reason' => $reason],
                createdBy: $adminId,
            );
            $model->update([
                'status' => SellerSettlement::STATUS_REVERSED,
                'reversed_at' => now(),
                'reviewed_by_admin_id' => $adminId,
                'review_note' => $reason,
            ]);
            $this->notifySeller($model, 'settlement_reversed');
            return true;
        });
    }

    public function dispute(int|SellerSettlement $settlement, string $reason): bool
    {
        return DB::transaction(function () use ($settlement, $reason) {
            $model = SellerSettlement::query()->lockForUpdate()->find($settlement instanceof SellerSettlement ? $settlement->id : $settlement);
            if (! $model || $model->status !== SellerSettlement::STATUS_HELD) {
                return false;
            }
            $model->update(['status' => SellerSettlement::STATUS_DISPUTED, 'review_note' => $reason]);
            return true;
        });
    }

    /**
     * Holds the still-pending settlement when a seller refuses an approved
     * return. The refund reference is unique, so retries never debit or hold it
     * twice. Released settlements are flagged for manual recovery instead of
     * silently creating a negative wallet movement.
     */
    public function disputeForReturnRefusal(RefundRequest $refund, string $reason): ?SellerSettlementDispute
    {
        $order = $refund->order ?: Order::query()->find($refund->order_id);
        if (!$order || $order->seller_is !== 'seller' || !$order->seller_id) {
            return null;
        }

        return DB::transaction(function () use ($refund, $order, $reason): ?SellerSettlementDispute {
            $settlement = SellerSettlement::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if (!$settlement) {
                return null;
            }
            $existing = SellerSettlementDispute::query()
                ->where('reference_type', RefundRequest::class)
                ->where('reference_id', $refund->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            $amount = min((float) $settlement->amount, max(0, (float) $refund->amount));
            if ($amount <= 0) {
                return null;
            }
            $canHold = in_array($settlement->status, [SellerSettlement::STATUS_HELD, SellerSettlement::STATUS_DISPUTED], true);
            $dispute = SellerSettlementDispute::query()->create([
                'seller_settlement_id' => $settlement->id,
                'order_id' => $order->id,
                'seller_id' => $order->seller_id,
                'reference_type' => RefundRequest::class,
                'reference_id' => $refund->id,
                'amount' => $amount,
                'status' => $canHold ? 'held' : 'manual_review',
                'reason' => $reason,
                'metadata' => [
                    'refund_status' => $refund->status,
                    'settlement_status_before' => $settlement->status,
                    'automatic_financial_hold' => $canHold,
                ],
            ]);

            if ($canHold) {
                $settlement->update([
                    'status' => SellerSettlement::STATUS_DISPUTED,
                    'review_note' => $reason,
                    'metadata' => array_merge($settlement->metadata ?? [], [
                        'return_dispute_id' => $dispute->id,
                        'return_refund_request_id' => $refund->id,
                        'disputed_amount' => $amount,
                    ]),
                ]);
            }

            return $dispute;
        });
    }

    /** Administrative override used by the finance center for a specific order. */
    public function setDueAt(int|SellerSettlement $settlement, Carbon $dueAt, ?int $adminId = null, ?string $note = null): ?SellerSettlement
    {
        return DB::transaction(function () use ($settlement, $dueAt, $adminId, $note) {
            $model = SellerSettlement::query()->lockForUpdate()->find($settlement instanceof SellerSettlement ? $settlement->id : $settlement);
            if (! $model || ! in_array($model->status, [SellerSettlement::STATUS_HELD, SellerSettlement::STATUS_DISPUTED], true)) {
                return null;
            }
            if ($dueAt->lt($model->delivered_at)) {
                throw new DomainException('seller_settlement_due_date_before_delivery');
            }
            $model->update([
                'due_at' => $dueAt,
                'dispute_until' => $dueAt,
                'reviewed_by_admin_id' => $adminId,
                'review_note' => $note,
            ]);
            return $model->fresh();
        });
    }

    public function calculateDueAt(Carbon $deliveredAt): Carbon
    {
        $cursor = $deliveredAt->copy();
        $remaining = $this->holdDays();
        while ($remaining > 0) {
            $cursor->addDay();
            if ($this->isWorkingDay($cursor)) {
                $remaining--;
            }
        }
        return $cursor->endOfDay();
    }

    public function holdDays(): int
    {
        $value = getWebConfig(name: 'seller_settlement_hold_days');
        return max(0, min(45, is_numeric($value) ? (int) $value : 45));
    }

    public function workingDays(): array
    {
        $value = getWebConfig(name: 'seller_settlement_working_days');
        if (is_string($value)) {
            $value = json_decode($value, true) ?: array_map('intval', explode(',', $value));
        }
        if (! is_array($value)) {
            return [0, 1, 2, 3, 4];
        }
        return array_values(array_unique(array_filter(array_map('intval', $value), fn (int $day): bool => $day >= 0 && $day <= 6)));
    }

    public function holidays(): array
    {
        $value = getWebConfig(name: 'seller_settlement_holidays');
        if (is_string($value)) {
            $value = json_decode($value, true) ?: preg_split('/[,\\s]+/', trim($value));
        }
        return is_array($value) ? array_values(array_filter(array_map(fn ($date) => (string) $date, $value))) : [];
    }

    public function timezone(): string
    {
        return (string) (getWebConfig(name: 'timezone') ?: config('app.timezone', 'Africa/Cairo'));
    }

    public function preDueAlertDays(): int
    {
        $value = getWebConfig(name: 'seller_settlement_pre_due_alert_days');
        return max(1, min(30, is_numeric($value) ? (int) $value : 5));
    }

    public function overdueEscalationDays(): int
    {
        $value = getWebConfig(name: 'seller_settlement_overdue_escalation_days');
        return max(1, min(30, is_numeric($value) ? (int) $value : 2));
    }

    private function isWorkingDay(Carbon $date): bool
    {
        return in_array($date->dayOfWeek, $this->workingDays(), true)
            && ! in_array($date->toDateString(), $this->holidays(), true);
    }

    private function notifySeller(SellerSettlement $settlement, string $event): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }
        $messages = [
            'settlement_held' => 'seller_settlement_notification_held',
            'settlement_released' => 'seller_settlement_notification_released',
            'settlement_reversed' => 'seller_settlement_notification_reversed',
            'settlement_upcoming' => 'seller_settlement_notification_upcoming',
            'settlement_overdue' => 'seller_settlement_notification_overdue',
            'settlement_escalated' => 'seller_settlement_notification_escalated',
        ];
        $locale = strtolower((string) ($settlement->seller?->app_language ?? 'en'));
        $locale = in_array($locale, ['ar', 'ar-sa', 'sa'], true) ? 'sa' : 'en';
        $translations = include(base_path('resources/lang/' . $locale . '/new-messages.php'));
        $title = $translations['seller_settlement_notification_title'] ?? 'Order settlement update';
        $description = $translations[$messages[$event] ?? ''] ?? ($locale === 'sa' ? 'تم تحديث تسوية التاجر.' : 'Seller settlement updated.');
        Notification::create([
            'sent_by' => 'admin',
            'sent_to' => 'seller',
            'seller_id' => $settlement->seller_id,
            'title' => $title,
            'description' => $description,
            'notification_count' => 1,
            'status' => 1,
        ]);
    }
}
