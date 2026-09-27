<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerStockViolation;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

class SellerStockMonitoringService
{
    public const ALERT_LOW_STOCK = 'low_stock';
    public const ALERT_OUT_OF_STOCK = 'out_of_stock';

    /**
     * Returns alerts only. It deliberately does not create a violation or deduct money.
     */
    public function productsNeedingAttention(?int $sellerId = null): Builder
    {
        return Product::query()
            ->with('seller')
            ->where('added_by', 'seller')
            ->where('product_type', 'physical')
            ->where('request_status', 1)
            ->when($sellerId, fn (Builder $query) => $query->where('user_id', $sellerId))
            ->where(function (Builder $query) {
                $query->where('current_stock', '<=', 0)
                    ->orWhereRaw('current_stock <= COALESCE(NULLIF((SELECT stock_limit FROM sellers WHERE sellers.id = products.user_id), 0), ?)', [max(1, (int) getWebConfig(name: 'stock_limit'))]);
            });
    }

    public function alertTypeFor(Product $product, ?Seller $seller = null): ?string
    {
        if ($product->product_type !== 'physical' || (int) $product->request_status !== 1) {
            return null;
        }
        if ((int) $product->current_stock <= 0) {
            return self::ALERT_OUT_OF_STOCK;
        }

        $seller ??= $product->seller;
        $limit = (int) ($seller?->stock_limit ?? 0);
        $limit = $limit > 0 ? $limit : max(1, (int) getWebConfig(name: 'stock_limit'));

        return (int) $product->current_stock <= $limit ? self::ALERT_LOW_STOCK : null;
    }

    public function recordManualDecision(
        Product $product,
        Seller $seller,
        string $decision,
        string $reason,
        int $adminId,
        ?float $penaltyAmount = null,
        ?string $policyReference = null,
        ?int $orderId = null,
    ): SellerStockViolation {
        if ($product->added_by !== 'seller' || (int) $product->user_id !== (int) $seller->id) {
            throw new DomainException('seller_stock_violation_product_mismatch');
        }
        if (!in_array($decision, [SellerStockViolation::DECISION_WARNING, SellerStockViolation::DECISION_PENALTY], true)) {
            throw new DomainException('invalid_seller_stock_violation_decision');
        }
        if (trim($reason) === '') {
            throw new DomainException('seller_stock_violation_reason_is_required');
        }
        if ($decision === SellerStockViolation::DECISION_PENALTY && (!$penaltyAmount || $penaltyAmount <= 0)) {
            throw new DomainException('seller_stock_violation_penalty_amount_is_required');
        }

        $alertType = $this->alertTypeFor($product, $seller);
        if (!$alertType) {
            throw new DomainException('seller_product_has_no_stock_alert');
        }

        return SellerStockViolation::create([
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'order_id' => $orderId,
            'alert_type' => $alertType,
            'decision' => $decision,
            'policy_reference' => $policyReference,
            'reason' => $reason,
            'penalty_amount' => $decision === SellerStockViolation::DECISION_PENALTY ? $penaltyAmount : null,
            'decided_by' => $adminId,
            'detected_at' => now(),
            'decided_at' => now(),
        ]);
    }
}
