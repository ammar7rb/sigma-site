<?php

namespace App\Services;

use App\Models\BusinessSetting;
use DomainException;

class SellerBalanceFundingSettingsService
{
    public const KEY = 'seller_balance_funding';

    public function defaults(): array
    {
        return [
            'offline_enabled' => true,
            'digital_enabled' => true,
            'min_amount' => 1,
            'max_amount' => 0,
            'digital_gateways' => [],
            'company_accounts' => [],
            'offline_review_required' => true,
            'digital_webhook_required' => true,
        ];
    }

    public function get(): array
    {
        $value = BusinessSetting::query()->where('type', self::KEY)->value('value');
        $decoded = is_string($value) ? json_decode($value, true) : [];
        $settings = array_merge($this->defaults(), is_array($decoded) ? $decoded : []);
        $settings['offline_enabled'] = (bool) $settings['offline_enabled'];
        $settings['digital_enabled'] = (bool) $settings['digital_enabled'];
        $settings['min_amount'] = max((float) $settings['min_amount'], 0.01);
        $settings['max_amount'] = max((float) $settings['max_amount'], 0);
        $settings['digital_gateways'] = array_values(array_filter((array) $settings['digital_gateways'], 'is_string'));
        $settings['company_accounts'] = array_values(array_filter((array) $settings['company_accounts'], fn ($account) => is_array($account)));
        // These are safety guarantees for the funding workflow, not user toggles.
        $settings['offline_review_required'] = true;
        $settings['digital_webhook_required'] = true;

        return $settings;
    }

    public function update(array $settings): array
    {
        $defaults = $this->defaults();
        $normalized = [
            'offline_enabled' => (bool) ($settings['offline_enabled'] ?? false),
            'digital_enabled' => (bool) ($settings['digital_enabled'] ?? false),
            'min_amount' => max((float) ($settings['min_amount'] ?? $defaults['min_amount']), 0.01),
            'max_amount' => max((float) ($settings['max_amount'] ?? 0), 0),
            'digital_gateways' => array_values(array_unique(array_filter((array) ($settings['digital_gateways'] ?? []), 'is_string'))),
            'company_accounts' => array_values(array_filter((array) ($settings['company_accounts'] ?? []), fn ($account) => is_array($account))),
            'offline_review_required' => true,
            'digital_webhook_required' => true,
        ];
        if ($normalized['max_amount'] > 0 && $normalized['max_amount'] < $normalized['min_amount']) {
            throw new DomainException('seller_balance_funding_max_below_min');
        }

        BusinessSetting::query()->updateOrCreate(
            ['type' => self::KEY],
            ['value' => json_encode($normalized, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]
        );
        clearWebConfigCacheKeys();

        return $this->get();
    }

    public function assertAmountAllowed(float $amount): void
    {
        $settings = $this->get();
        if ($amount < $settings['min_amount']) {
            throw new DomainException('seller_balance_funding_amount_below_min');
        }
        if ($settings['max_amount'] > 0 && $amount > $settings['max_amount']) {
            throw new DomainException('seller_balance_funding_amount_above_max');
        }
    }

    public function isGatewayAllowed(string $gateway): bool
    {
        $selected = $this->get()['digital_gateways'];
        return $selected === [] || in_array($gateway, $selected, true);
    }
}
