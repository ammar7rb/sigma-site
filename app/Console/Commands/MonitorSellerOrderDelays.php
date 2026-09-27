<?php

namespace App\Console\Commands;

use App\Services\OrderWorkflowService;
use Illuminate\Console\Command;

class MonitorSellerOrderDelays extends Command
{
    protected $signature = 'orders:monitor-seller-delays';
    protected $description = 'Create idempotent warnings and escalations for delayed seller orders';

    public function handle(OrderWorkflowService $workflow): int
    {
        $result = $workflow->monitorSellerDelays();
        $this->info("Warnings: {$result['warning']}; escalations: {$result['escalation']}");
        return self::SUCCESS;
    }
}
