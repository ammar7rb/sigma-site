<?php

namespace App\Console\Commands;

use App\Services\PostPurchaseInvoiceService;
use Illuminate\Console\Command;

class ExpirePostPurchaseInvoices extends Command
{
    protected $signature = 'post-purchase-invoices:expire';
    protected $description = 'Reconciles and then expires overdue tax and customer-insurance invoices';

    public function handle(PostPurchaseInvoiceService $invoices): int
    {
        $created = $invoices->createMissingInvoicesForPaidOrders();
        $expired = $invoices->expireOverdueInvoices();
        $this->info("Created {$created}; expired {$expired} post-purchase invoice(s).");

        return self::SUCCESS;
    }
}
