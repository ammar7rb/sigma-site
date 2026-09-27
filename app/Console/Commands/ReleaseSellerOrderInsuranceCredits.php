<?php

namespace App\Console\Commands;

use App\Services\SellerOrderInsuranceService;
use Illuminate\Console\Command;

class ReleaseSellerOrderInsuranceCredits extends Command
{
    protected $signature = 'seller-order-insurances:release-reusable {--limit=500}';
    protected $description = 'Release due seller order-insurance deposits to the restricted reusable credit balance';

    public function handle(SellerOrderInsuranceService $service): int
    {
        $expired = $service->expireDue((int) $this->option('limit'));
        $count = $service->releaseReusableCredits((int) $this->option('limit'));
        $this->info("Expired {$expired} unpaid seller order insurance record(s) for administrative review.");
        $this->info("Released {$count} seller order insurance credit record(s).");
        return self::SUCCESS;
    }
}
