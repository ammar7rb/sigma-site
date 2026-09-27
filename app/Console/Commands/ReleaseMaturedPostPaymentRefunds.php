<?php

namespace App\Console\Commands;

use App\Services\PostPaymentRefundService;
use Illuminate\Console\Command;

class ReleaseMaturedPostPaymentRefunds extends Command
{
    protected $signature = 'commerce:release-matured-refunds';
    protected $description = 'Move matured purchase and insurance refunds from held to their dedicated available balances.';

    public function handle(PostPaymentRefundService $service): int
    {
        $this->info((string) $service->releaseMatured());

        return self::SUCCESS;
    }
}
