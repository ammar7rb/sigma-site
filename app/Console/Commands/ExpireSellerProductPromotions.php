<?php

namespace App\Console\Commands;

use App\Services\SellerProductPromotionService;
use Illuminate\Console\Command;

class ExpireSellerProductPromotions extends Command
{
    protected $signature = 'seller-promotions:expire';

    protected $description = 'Expire seller search and homepage product promotions';

    public function handle(SellerProductPromotionService $service): int
    {
        $count = $service->expireDuePromotions();
        $this->info("Expired seller product promotions: {$count}");

        return self::SUCCESS;
    }
}
