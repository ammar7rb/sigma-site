<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderShippingDecisionService;
use App\Services\OrderWorkflowService;
use Illuminate\Console\Command;

class BackfillOrderOperationalData extends Command
{
    protected $signature = 'orders:backfill-operational-data';
    protected $description = 'Backfill shipment references, address snapshots, status timestamps, and seller queue positions';

    public function handle(OrderShippingDecisionService $shipping, OrderWorkflowService $workflow): int
    {
        $count = 0;
        Order::query()->whereNull('shipment_reference')->orderBy('id')->chunkById(100, function ($orders) use ($shipping, &$count): void {
            foreach ($orders as $order) {
                $shipping->ensureShipmentIdentity($order);
                if (!$order->operational_status_updated_at) {
                    $order->update(['operational_status_updated_at' => $order->updated_at ?: $order->created_at ?: now()]);
                }
                $count++;
            }
        });

        Order::query()->where('seller_is', 'seller')->whereNotNull('seller_id')->distinct()->pluck('seller_id')
            ->each(fn ($sellerId) => $workflow->refreshSellerQueue((int) $sellerId));

        $this->info("Backfilled orders: {$count}");
        return self::SUCCESS;
    }
}
