<?php

namespace App\Console\Commands;

use App\Services\CustomerInsuranceBalanceService;
use Illuminate\Console\Command;

class ReleaseCustomerInsuranceBalances extends Command
{
    protected $signature = 'customer-insurance:release-due {--limit=500}';
    protected $description = 'Release matured customer order insurance to the separate insurance balance';

    public function handle(CustomerInsuranceBalanceService $service): int
    {
        $count = $service->releaseDue((int) $this->option('limit'));
        $this->info("Released customer insurance balances: {$count}");
        return self::SUCCESS;
    }
}
