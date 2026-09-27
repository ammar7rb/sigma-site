<?php

namespace App\Services;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Schema;

class WorkflowFeatureService
{
    public function activationCenterEnabled(): bool
    {
        return $this->booleanSetting(
            'feature_activation_center',
            (bool) config('account_workflows.activation_center.enabled', true)
        );
    }

    public function postPurchaseTaxInvoiceEnabled(): bool
    {
        return $this->booleanSetting(
            'feature_post_purchase_tax_invoice_v3',
            (bool) config('account_workflows.commerce_contract.post_purchase_tax_invoice_enabled', false)
        );
    }

    public function configurableShippingPromisesEnabled(): bool
    {
        return $this->booleanSetting(
            'feature_configurable_shipping_promises',
            (bool) config('account_workflows.commerce_contract.configurable_shipping_promises_enabled', false)
        );
    }

    public function commerceContractVersion(): string
    {
        if (Schema::hasTable('business_settings')) {
            $stored = BusinessSetting::query()->where('type', 'commerce_contract_version')->value('value');
            if (is_string($stored) && trim($stored) !== '') {
                return trim($stored);
            }
        }

        return (string) config('account_workflows.commerce_contract.current_version', 'post_purchase_v3');
    }

    private function booleanSetting(string $key, bool $default): bool
    {
        if (! Schema::hasTable('business_settings')) {
            return $default;
        }

        $value = BusinessSetting::query()->where('type', $key)->value('value');
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
