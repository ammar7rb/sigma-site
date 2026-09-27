<?php

namespace App\Console\Commands;

use App\Services\SellerSettlementService;
use Illuminate\Console\Command;

class ReleaseSellerSettlements extends Command
{
    protected $signature = 'seller-settlements:release-due';

    protected $description = 'Release seller settlements whose dispute hold has ended';

    public function handle(SellerSettlementService $service): int
    {
        $alerts = $service->sendDueAlerts();
        $count = $service->releaseDue();
        $this->info("Settlement alerts: {$alerts['upcoming']} upcoming, {$alerts['overdue']} overdue, ".($alerts['escalated'] ?? 0)." escalated. Released: {$count}");
        return self::SUCCESS;
    }
}
