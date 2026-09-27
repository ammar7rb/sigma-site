<?php

namespace App\Console\Commands;

use App\Services\SellerProductEntitlementService;
use Illuminate\Console\Command;

class ExpireSellerProductEntitlements extends Command
{
    protected $signature = 'seller-products:expire-entitlements';

    protected $description = 'Deactivate seller products whose paid publication duration has expired';

    public function handle(SellerProductEntitlementService $service): int
    {
        $count = $service->expireDueEntitlements();
        $this->info("Expired seller product entitlements: {$count}");

        return self::SUCCESS;
    }
}
