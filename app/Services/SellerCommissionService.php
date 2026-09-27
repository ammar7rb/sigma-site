<?php

namespace App\Services;

use App\Models\Seller;

class SellerCommissionService
{
    public function getSummary(?Seller $seller): array
    {
        $defaultRate = (float) (getWebConfig(name: 'sales_commission') ?? 0);
        $hasCustomRate = $seller?->sales_commission_percentage !== null;
        $customRate = $hasCustomRate ? (float) $seller->sales_commission_percentage : null;
        $effectiveRate = $hasCustomRate ? $customRate : $defaultRate;

        return [
            'default_rate' => $defaultRate,
            'custom_enabled' => $hasCustomRate,
            'custom_rate' => $customRate,
            'effective_rate' => $effectiveRate,
            'source' => $hasCustomRate ? 'vendor_override' : 'system_default',
            'exempt' => $effectiveRate == 0.0,
        ];
    }

    public function calculate(string $sellerType, int $sellerId, float $orderTotal): float
    {
        if ($sellerType !== 'seller' || $orderTotal <= 0) {
            return 0;
        }

        $summary = $this->getSummary(Seller::find($sellerId));

        return round(($orderTotal / 100) * $summary['effective_rate'], 2);
    }

    public function updateOverride(Seller $seller, bool $customEnabled, ?float $rate): Seller
    {
        $seller->update([
            'sales_commission_percentage' => $customEnabled ? $rate : null,
        ]);

        return $seller->fresh();
    }
}
