<?php

namespace App\Services;

use InvalidArgumentException;

class OrderCommerceFeatureService
{
    public function enabled(string $feature): bool
    {
        $features = config('order_commerce.features', []);
        if (! array_key_exists($feature, $features)) {
            throw new InvalidArgumentException("Unknown order commerce feature: {$feature}");
        }

        return filter_var($features[$feature], FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return collect(config('order_commerce.features', []))
            ->map(fn ($value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN))
            ->all();
    }

    public function contractVersion(): string
    {
        return (string) config('order_commerce.contract_version', '2026-08-v2');
    }
}
