<?php

namespace App\Console\Commands;

use App\Services\OrderWorkflowService;
use Illuminate\Console\Command;

class ExpireCustomerDeliveryConfirmations extends Command
{
    protected $signature = 'orders:expire-customer-confirmations';
    protected $description = 'Close expired customer receipt confirmation windows without removing return rights';

    public function handle(OrderWorkflowService $workflow): int
    {
        $count = $workflow->expireCustomerConfirmations();
        $this->info("Expired confirmations: {$count}");
        return self::SUCCESS;
    }
}
