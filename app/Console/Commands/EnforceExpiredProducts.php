<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\SellerProductReviewService;
use Illuminate\Console\Command;

class EnforceExpiredProducts extends Command
{
    protected $signature = 'products:enforce-expiry {--limit=500}';

    protected $description = 'Unpublish products whose declared expiry date has been reached';

    public function handle(SellerProductReviewService $reviewService): int
    {
        $count = 0;
        Product::query()->withoutGlobalScopes()
            ->where('status', 1)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', today())
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Product $product) use ($reviewService, &$count) {
                if ($product->added_by === 'seller') {
                    $reviewService->transition($product, SellerProductReviewService::SUSPENDED, 'product_expired');
                } else {
                    $product->forceFill(['status' => 0, 'featured' => 0])->save();
                }
                $count++;
            });

        if ($count > 0) {
            cacheRemoveByType(type: 'products');
        }
        $this->info("Unpublished {$count} expired product(s).");

        return self::SUCCESS;
    }
}
