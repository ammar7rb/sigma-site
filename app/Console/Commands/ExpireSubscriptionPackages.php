<?php

namespace App\Console\Commands;

use App\Models\CustomerPurchasePackageSubscription;
use App\Models\SellerPackageSubscription;
use Illuminate\Console\Command;

class ExpireSubscriptionPackages extends Command
{
    protected $signature = 'packages:expire-subscriptions';

    protected $description = 'Mark expired seller and customer package subscriptions';

    public function handle(): int
    {
        $sellerCount = SellerPackageSubscription::query()
            ->where('status', SellerPackageSubscription::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => SellerPackageSubscription::STATUS_EXPIRED]);

        $customerCount = CustomerPurchasePackageSubscription::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $this->info(sprintf('Expired %d seller and %d customer subscriptions.', $sellerCount, $customerCount));

        return self::SUCCESS;
    }
}
