<?php

namespace App\Console\Commands;

use App\Services\CustomerPostPurchaseInsuranceService;
use App\Services\AdminOrderGateService;
use Illuminate\Console\Command;

class ReleaseCustomerPurchaseRefunds extends Command
{
    protected $signature = 'orders:release-customer-purchase-refunds {--limit=100}';
    protected $description = 'Credit due purchase refunds to the customer purchase wallet';

    public function handle(CustomerPostPurchaseInsuranceService $service, AdminOrderGateService $adminGate): int
    {
        $limit = (int) $this->option('limit');
        $this->info((string) ($service->releaseDuePurchaseRefunds($limit) + $adminGate->releaseDueUninsuredRefunds($limit)));
        return self::SUCCESS;
    }
}
