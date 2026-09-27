<?php

namespace App\Services;

use App\Models\ShippingMethod;
use App\Models\Cart;
use App\Models\CartShipping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerThreeStepShippingService
{
    private const SETTING_METHOD_IDS = 'customer_three_step_shipping_method_ids';
    private ?array $syncedMethodIds = null;

    public function isEnabled(): bool
    {
        return (bool) getWebConfig(name: 'customer_three_step_shipping_status');
    }

    public function getAvailableMethodsForSeller(int|string|null $sellerId, string $sellerType): Collection
    {
        if (!$this->isEnabled()) {
            return ShippingMethod::where(['status' => 1])
                ->when($sellerType === 'admin', fn ($query) => $query->where('creator_type', 'admin'))
                ->when($sellerType !== 'admin', fn ($query) => $query->where(['creator_id' => $sellerId, 'creator_type' => $sellerType]))
                ->get();
        }

        // Admin three-step delivery is the only selectable source for every seller.
        $this->syncMethods();

        return $this->getConfiguredMethodsQuery()
            ->get()
            ->filter(fn (ShippingMethod $method) => $this->isMethodCurrentlyAvailable($method))
            ->values();
    }

    public function getAllVisibleMethods(): Collection
    {
        if (!$this->isEnabled()) {
            return ShippingMethod::where(['status' => 1])->get();
        }

        $this->syncMethods();
        $threeStepIds = $this->getStoredMethodIds();

        // Do not expose legacy seller-defined methods while admin pricing is active.
        return ShippingMethod::whereIn('id', $threeStepIds)
            ->where(['status' => 1])
            ->get()
            ->filter(fn (ShippingMethod $method) => $this->isMethodCurrentlyAvailable($method))
            ->values();
    }

    public function getThreeStepOptionsWithAvailability(): Collection
    {
        $this->syncMethods();
        $ids = $this->getStoredMethodIds();

        return $this->applyMethodOrdering(ShippingMethod::whereIn('id', $ids), $ids)
            ->get()
            ->map(function (ShippingMethod $method) {
                return [
                    'id' => $method->id,
                    'title' => $method->title,
                    'duration' => $method->duration,
                    'cost' => (float) $method->cost,
                    'option_key' => $this->getOptionKeyForMethod($method),
                    'enabled' => (bool) $method->status,
                    'available' => $this->isMethodCurrentlyAvailable($method),
                ];
            })
            ->values();
    }

    public function resolveSelectableMethod(int|string $methodId): ?ShippingMethod
    {
        $method = ShippingMethod::where('id', $methodId)->first();

        if (!$method || !$method->status) {
            return null;
        }

        if (!$this->isEnabled()) {
            return $method;
        }

        $this->syncMethods();
        $threeStepIds = array_map('intval', $this->getStoredMethodIds());

        // Reject direct requests that try to select a seller-defined shipping method.
        if (!in_array((int) $method->id, $threeStepIds, true)) {
            return null;
        }

        return $this->isMethodCurrentlyAvailable($method) ? $method : null;
    }

    public function isMethodCurrentlyAvailable(ShippingMethod $method): bool
    {
        if (!$this->isEnabled()) {
            return (bool) $method->status;
        }

        $optionKey = $this->getOptionKeyForMethod($method);
        if (!$optionKey) {
            return $method->creator_type !== 'admin' && (bool) $method->status;
        }

        $config = $this->getOptionConfig($optionKey);
        if (!$config['status']) {
            return false;
        }

        return true;
    }

    public function syncMethods(): array
    {
        $storedIds = $this->getStoredMethodIds();
        $syncedIds = [];

        foreach ($this->getOptionKeys() as $optionKey) {
            $config = $this->getOptionConfig($optionKey);
            // Reuse the legacy same-day row for Sigma so existing cart/order
            // references remain valid after merging the two express options.
            $storedMethodId = $storedIds[$optionKey]
                ?? ($optionKey === 'sigma' ? ($storedIds['same_day'] ?? null) : null);
            $method = $storedMethodId ? ShippingMethod::find($storedMethodId) : null;

            if (!$method) {
                $method = ShippingMethod::where('creator_id', 1)
                    ->where('creator_type', 'admin')
                    ->whereIn('title', $optionKey === 'sigma'
                        ? [$config['title'], 'Same Day Delivery', 'Sigma Delivery']
                        : [$config['title']])
                    ->first();
            }

            $data = [
                'creator_id' => 1,
                'creator_type' => 'admin',
                'title' => $config['title'],
                'duration' => $config['duration'],
                'cost' => $config['cost'],
                'status' => $config['status'],
            ];

            if ($method) {
                $method->update($data);
            } else {
                $method = ShippingMethod::create($data);
            }

            $syncedIds[$optionKey] = $method->id;
        }

        // The old next-day row is no longer a selectable customer-facing method.
        $legacyNextDayId = $storedIds['next_day'] ?? null;
        if ($legacyNextDayId && !in_array((int) $legacyNextDayId, array_map('intval', $syncedIds), true)) {
            ShippingMethod::whereKey($legacyNextDayId)->update(['status' => 0]);
        }

        DB::table('business_settings')->updateOrInsert(
            ['type' => self::SETTING_METHOD_IDS],
            ['value' => json_encode($syncedIds), 'updated_at' => now(), 'created_at' => now()]
        );

        $this->syncedMethodIds = $syncedIds;

        return $syncedIds;
    }

    public function getOptionKeyForMethod(ShippingMethod $method): ?string
    {
        $storedIds = $this->getStoredMethodIds();
        foreach ($storedIds as $optionKey => $methodId) {
            if ((int) $methodId === (int) $method->id) {
                return $optionKey;
            }
        }

        return null;
    }

    /** Legacy API kept for older clients; Sigma never offers same-day delivery. */
    public function getSameDayCutoff(): ?string
    {
        return null;
    }

    public function calculateCartGroupCost(string $cartGroupId, ShippingMethod $method, array|object|null $address = null): ?float
    {
        return $this->getCartGroupShippingBreakdown($cartGroupId, $method, $address)['total_shipping_cost'] ?? null;
    }

    /** The distance quote always uses the normal delivery option internally. */
    public function quoteAutomaticDistanceDelivery(string $cartGroupId, array|object|null $address): ?array
    {
        $options = $this->quoteAutomaticDistanceDeliveryOptions($cartGroupId, $address);
        $normal = $options['options']['normal'] ?? null;

        return $normal ? [
            'method' => $normal['method'],
            'distance_km' => $options['distance_km'],
            'cost' => $normal['cost'],
            'breakdown' => $normal['breakdown'],
        ] : null;
    }

    /**
     * Quotes both prepaid delivery promises from the same customer location.
     * The caller deliberately receives prices only; it must persist a quote
     * after the customer explicitly chooses Normal or Sigma.
     */
    public function quoteAutomaticDistanceDeliveryOptions(string $cartGroupId, array|object|null $address): ?array
    {
        $this->syncMethods();
        $zoneService = app(EgyptShippingRateService::class);
        $distanceKm = $zoneService->pricingDistanceFromDispatch($address);
        if ($distanceKm === null) {
            return null;
        }

        $addressData = is_object($address)
            ? (method_exists($address, 'toArray') ? $address->toArray() : get_object_vars($address))
            : (array) $address;
        $addressData['distance_km'] = $distanceKm;
        $options = [];

        foreach ($this->getOptionKeys() as $optionKey) {
            $methodId = $this->getStoredMethodIds()[$optionKey] ?? null;
            $method = $methodId ? ShippingMethod::find($methodId) : null;
            if (!$method || !$this->isMethodCurrentlyAvailable($method)) {
                continue;
            }

            $breakdown = $this->getCartGroupShippingBreakdown($cartGroupId, $method, $addressData);
            if (!$breakdown || $breakdown['total_shipping_cost'] === null) {
                continue;
            }

            $promise = app(ShippingPromiseService::class)->promise($optionKey);
            $options[$optionKey] = [
                'method' => $method,
                'cost' => (float) $breakdown['total_shipping_cost'],
                'breakdown' => $breakdown,
                'promise' => $promise,
            ];
        }

        // This checkout is intentionally two-choice: neither option may be
        // silently substituted for the other.
        if (!isset($options['normal'], $options['sigma'])) {
            return null;
        }

        return ['distance_km' => $distanceKm, 'options' => $options];
    }

    public function getCartGroupShippingBreakdown(string $cartGroupId, ShippingMethod $method, array|object|null $address = null): ?array
    {
        $optionKey = $this->getOptionKeyForMethod($method);
        if (!$optionKey) {
            return null;
        }

        $cartItems = Cart::with('allProducts')
            ->where('cart_group_id', $cartGroupId)
            ->where('product_type', 'physical')
            ->where('is_checked', 1)
            ->get();

        if ($cartItems->isEmpty()) {
            $cartItems = Cart::with('allProducts')
                ->where('cart_group_id', $cartGroupId)
                ->where('product_type', 'physical')
                ->get();
        }

        // Sigma is one customer-facing option, but its product rate follows the
        // delivery promise: same-day rate before the cutoff and next-day rate after it.
        $rateColumn = $this->getRateColumnForOption($optionKey);
        $totalQuantity = 0;
        $shippingCost = 0.0;

        // When zone rates are configured, the customer's Egyptian address is the
        // authoritative quote. Do this before reading product-level rates so a
        // correctly configured zone does not fail merely because legacy product
        // shipping columns are empty.
        $zoneCost = null;
        $zoneQuote = null;
        $zoneRatesConfigured = false;
        if ($address !== null) {
            $zoneService = app(EgyptShippingRateService::class);
            if (!$zoneService->isEgyptAddress($address)) {
                return null;
            }
            $zoneRatesConfigured = $zoneService->hasConfiguredRates();
            if ($zoneRatesConfigured) {
                $addressData = is_object($address)
                    ? (method_exists($address, 'toArray') ? $address->toArray() : get_object_vars($address))
                    : $address;
                $distanceKm = isset($addressData['distance_km']) && is_numeric($addressData['distance_km'])
                    ? (float) $addressData['distance_km']
                    : $zoneService->pricingDistanceFromDispatch($address);
                if ($distanceKm === null) {
                    return null;
                }
                $zoneQuote = $zoneService->quote(
                    $address,
                    $optionKey,
                    $distanceKm,
                    filter_var($addressData['is_peak'] ?? false, FILTER_VALIDATE_BOOLEAN),
                );
                $zoneCost = $zoneQuote['total'] ?? null;
                if ($zoneCost === null) {
                    return null;
                }
            }
        }

        foreach ($cartItems as $cartItem) {
            $quantity = max(0, (int) $cartItem->quantity);
            $totalQuantity += $quantity;
            if (!$zoneRatesConfigured) {
                $product = $cartItem->allProducts;
                $rate = $product?->{$rateColumn};

                // A product without an admin rate must never silently receive free shipping.
                if ($rate === null) {
                    return null;
                }

                $shippingCost += (float) $rate * $quantity;
            }
        }

        if ($zoneRatesConfigured) {
            $shippingCost = (float) $zoneCost;
        }

        $totalShippingCost = $this->applyQuantitySurcharge($shippingCost, $totalQuantity);

        // This snapshot lets the admin review the exact group calculation after settings are changed.
        return [
            'shipping_quantity' => $totalQuantity,
            'shipping_product_subtotal' => round($shippingCost, 12),
            'shipping_quantity_surcharge' => round($totalShippingCost - $shippingCost, 12),
            'total_shipping_cost' => $totalShippingCost,
            'shipping_price_snapshot' => $zoneQuote ? array_merge($zoneQuote, [
                'quantity' => $totalQuantity,
                'quantity_surcharge' => round($totalShippingCost - $shippingCost, 12),
                'final_total' => $totalShippingCost,
            ]) : [
                'source' => 'legacy_product_rate',
                'option' => $optionKey,
                'quantity' => $totalQuantity,
                'product_subtotal' => round($shippingCost, 12),
                'quantity_surcharge' => round($totalShippingCost - $shippingCost, 12),
                'final_total' => $totalShippingCost,
            ],
        ];
    }

    public function recalculateCartGroupCost(string $cartGroupId, array|object|null $address = null): ?float
    {
        $shipping = CartShipping::where('cart_group_id', $cartGroupId)->first();
        if (!$shipping) {
            return 0.0;
        }

        $method = $this->resolveSelectableMethod($shipping->shipping_method_id);
        if (!$method) {
            return null;
        }

        $cost = $this->calculateCartGroupCost($cartGroupId, $method, $address);
        if ($cost === null) {
            // Remove a stale quote if a product rate was cleared after the customer selected delivery.
            $shipping->update(['shipping_cost' => 0]);
            return null;
        }

        // Store one calculated total per seller cart group to avoid duplicate line charges.
        $shipping->update(['shipping_cost' => $cost]);
        Cart::where('cart_group_id', $cartGroupId)->update([
            'shipping_cost' => 0,
            'shipping_type' => 'order_wise',
        ]);

        return $cost;
    }

    private function getConfiguredMethodsQuery()
    {
        $ids = $this->getStoredMethodIds();

        return $this->applyMethodOrdering(ShippingMethod::whereIn('id', $ids), $ids)
            ->where('creator_type', 'admin')
            ->where('status', 1);
    }

    private function applyMethodOrdering($query, array $ids)
    {
        if (DB::connection()->getDriverName() === 'mysql' && $ids) {
            $orderedIds = implode(',', array_map('intval', $ids));
            return $query->orderByRaw("FIELD(id, {$orderedIds})");
        }

        return $query->orderBy('id');
    }

    private function getStoredMethodIds(): array
    {
        if ($this->syncedMethodIds !== null) {
            return $this->syncedMethodIds;
        }

        $setting = getWebConfig(name: self::SETTING_METHOD_IDS);
        $ids = is_array($setting) ? $setting : json_decode($setting ?: '[]', true);

        return is_array($ids) ? array_filter($ids) : [];
    }

    private function getOptionKeys(): array
    {
        return ['sigma', 'normal'];
    }

    private function getOptionConfig(string $optionKey): array
    {
        $prefix = 'customer_shipping_' . $optionKey;
        $defaults = [
            'sigma' => ['title' => 'Sigma Shipping'],
            'normal' => ['title' => 'Normal Shipping'],
        ];
        $promise = app(ShippingPromiseService::class)->config($optionKey);
        $duration = $promise['mode'] === 'fixed'
            ? $promise['min_days'] . ' أيام عمل'
            : $promise['min_days'] . '-' . $promise['max_days'] . ' أيام عمل';

        $status = (bool) getWebConfig(name: $prefix . '_status');
        if ($optionKey === 'sigma' && getWebConfig(name: $prefix . '_status') === null) {
            $status = (bool) getWebConfig(name: 'customer_shipping_same_day_status')
                || (bool) getWebConfig(name: 'customer_shipping_next_day_status');
        }

        return [
            'status' => $status,
            'title' => getWebConfig(name: $prefix . '_title')
                ?: ($optionKey === 'sigma' ? getWebConfig(name: 'customer_shipping_same_day_title') : null)
                ?: $defaults[$optionKey]['title'],
            'duration' => $duration,
            'cost' => (float) (getWebConfig(name: $prefix . '_cost')
                ?? ($optionKey === 'sigma' ? getWebConfig(name: 'customer_shipping_next_day_cost') : 0)
                ?? 0),
        ];
    }

    private function applyQuantitySurcharge(float $shippingCost, int $totalQuantity): float
    {
        $threshold = (int) (getWebConfig(name: 'customer_product_shipping_quantity_threshold') ?? 0);
        if ($threshold <= 0 || $totalQuantity <= $threshold) {
            return round($shippingCost, 12);
        }

        $type = getWebConfig(name: 'customer_product_shipping_extra_charge_type') ?: 'percent';
        $value = (float) (getWebConfig(name: 'customer_product_shipping_extra_charge_value') ?? 0);
        $extra = $type === 'fixed' ? $value : ($shippingCost * $value / 100);

        // The surcharge is applied once after all product rates in the seller group are summed.
        return round($shippingCost + max(0, $extra), 12);
    }

    private function getRateColumnForOption(string $optionKey): string
    {
        if ($optionKey === 'sigma') {
            return 'next_day_shipping_cost';
        }

        return $optionKey . '_shipping_cost';
    }
}
