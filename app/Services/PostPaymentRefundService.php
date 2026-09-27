<?php

namespace App\Services;

use App\Events\OrderStatusEvent;
use App\Models\CustomerPurchaseBalance;
use App\Models\Order;
use App\Models\OrderRefundLedger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PostPaymentRefundService
{
    public function refundStockOut(
        Order $order,
        float $purchaseAmount,
        float $insuranceAmount,
        int $adminId,
        string $note,
        ?Carbon $availableAt = null
    ): OrderRefundLedger {
        if ($order->payment_status !== 'paid') {
            throw new RuntimeException('Only paid orders can use the post-payment stock-out refund.');
        }

        $purchaseAmount = round(max(0, $purchaseAmount), 4);
        $insuranceAmount = round(max(0, $insuranceAmount), 4);
        if ($purchaseAmount + $insuranceAmount <= 0) {
            throw new RuntimeException('A refund amount is required.');
        }

        $availableAt ??= now()->addDays(max(0, (int) (getWebConfig(name: 'post_payment_refund_hold_days') ?? 14)));
        $key = "stock-out:{$order->id}";

        $ledger = DB::transaction(function () use ($order, $purchaseAmount, $insuranceAmount, $adminId, $note, $availableAt, $key) {
            $existing = OrderRefundLedger::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $purchaseBalance = CustomerPurchaseBalance::firstOrCreate(
                ['customer_id' => $order->customer_id],
                ['available_amount' => 0, 'held_amount' => 0]
            );
            $purchaseBalance->increment('held_amount', $purchaseAmount);

            if ($insuranceAmount > 0 && Schema::hasTable('customer_insurance_balances')) {
                // The refunded portion must not also mature through order insurance.
                if (Schema::hasTable('order_insurances')) {
                    $heldInsurance = \App\Models\OrderInsurance::where('order_id', $order->id)->where('status', 'held')->lockForUpdate()->first();
                    if ($heldInsurance) {
                        $remaining = max(0, (float) $heldInsurance->amount - $insuranceAmount);
                        $heldInsurance->update(['amount' => $remaining, 'status' => $remaining > 0 ? 'held' : 'refunded']);
                    }
                }
                $insuranceBalance = DB::table('customer_insurance_balances')
                    ->where('customer_id', $order->customer_id)
                    ->lockForUpdate()
                    ->first();
                if ($insuranceBalance) {
                    DB::table('customer_insurance_balances')->where('customer_id', $order->customer_id)
                        ->increment('held_amount', $insuranceAmount);
                } else {
                    DB::table('customer_insurance_balances')->insert([
                        'customer_id' => $order->customer_id,
                        'available_amount' => 0,
                        'held_amount' => $insuranceAmount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $ledger = OrderRefundLedger::create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'idempotency_key' => $key,
                'reason' => 'post_payment_stock_out',
                'purchase_amount' => $purchaseAmount,
                'insurance_amount' => $insuranceAmount,
                'purchase_available_at' => $availableAt,
                'insurance_available_at' => $availableAt,
                'status' => 'held',
                'admin_id' => $adminId,
                'admin_note' => $note,
                'metadata' => [
                    'purchase_wallet' => 'customer_purchase_balance',
                    'insurance_wallet' => 'customer_insurance_balance',
                    'withdrawable' => false,
                ],
            ]);

            $order->forceFill(['order_status' => 'canceled'])->save();

            return $ledger;
        });

        if ($ledger->wasRecentlyCreated) {
            DB::afterCommit(fn () => OrderStatusEvent::dispatch(
                'post_payment_stock_out_refund_scheduled',
                'customer',
                $order->fresh()
            ));
        }

        return $ledger;
    }

    public function releaseMatured(): int
    {
        $released = 0;

        OrderRefundLedger::query()->where('status', 'held')
            ->where(function ($query) {
                $query->where('purchase_available_at', '<=', now())
                    ->orWhere('insurance_available_at', '<=', now());
            })->orderBy('id')->chunkById(100, function ($ledgers) use (&$released) {
                foreach ($ledgers as $ledger) {
                    $releasedOrder = DB::transaction(function () use ($ledger, &$released) {
                        $locked = OrderRefundLedger::whereKey($ledger->id)->lockForUpdate()->first();
                        if (! $locked || $locked->status !== 'held') {
                            return null;
                        }

                        $purchase = CustomerPurchaseBalance::where('customer_id', $locked->customer_id)->lockForUpdate()->first();
                        if ($purchase && $locked->purchase_available_at?->lte(now())) {
                            $amount = min((float) $purchase->held_amount, (float) $locked->purchase_amount);
                            $purchase->decrement('held_amount', $amount);
                            $purchase->increment('available_amount', $amount);
                        }

                        if ($locked->insurance_available_at?->lte(now()) && Schema::hasTable('customer_insurance_balances')) {
                            $insurance = DB::table('customer_insurance_balances')->where('customer_id', $locked->customer_id)->lockForUpdate()->first();
                            if ($insurance) {
                                $amount = min((float) $insurance->held_amount, (float) $locked->insurance_amount);
                                DB::table('customer_insurance_balances')->where('customer_id', $locked->customer_id)->update([
                                    'held_amount' => max(0, (float) $insurance->held_amount - $amount),
                                    'available_amount' => (float) $insurance->available_amount + $amount,
                                    'updated_at' => now(),
                                ]);
                            }
                        }

                        $locked->update(['status' => 'available']);
                        $released++;

                        return $locked->order()->first();
                    });

                    if ($releasedOrder) {
                        OrderStatusEvent::dispatch('post_payment_refund_available', 'customer', $releasedOrder);
                    }
                }
            });

        return $released;
    }
}
