<?php


use Illuminate\Support\Str;

if (!function_exists('getOrderSummary')) {
    function getOrderSummary(object $order): array
    {
        $sub_total = 0;
        $total_tax = $order['total_tax_amount'];
        $total_discount_on_product = 0;
        foreach ($order->details as $detail) {
            $sub_total += $detail->price * $detail->qty;
            $total_discount_on_product += $detail->discount;
        }
        $total_shipping_cost = $order['shipping_cost'];
        return [
            'subtotal' => $sub_total,
            'total_tax' => $total_tax,
            'total_discount_on_product' => $total_discount_on_product,
            'total_shipping_cost' => $total_shipping_cost,
        ];
    }
}

if (!function_exists('getUniqueId')) {
    function getUniqueId(): string
    {
        return rand(1000, 9999) . '-' . Str::random(5) . '-' . time();
    }
}


if (!function_exists('getOrderStatusList')) {
    function getOrderStatusList(): array
    {
        return [
            'pending',
            'confirmed',
            'processing',
            'out_for_delivery',
            'delivered',
            'returned',
            'failed',
            'canceled',
        ];
    }
}

if (!function_exists('formatOrderPaymentInfoValue')) {
    function formatOrderPaymentInfoValue(mixed $value, string $fallback = 'N/A'): string
    {
        if (is_array($value)) {
            $flattenedValue = collect($value)
                ->flatten()
                ->filter(fn($item) => is_scalar($item) && $item !== '')
                ->implode(', ');

            return $flattenedValue !== '' ? (string) $flattenedValue : $fallback;
        }

        if (is_null($value) || $value === '') {
            return $fallback;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: $fallback;
    }
}

if (!function_exists('getOfflinePaymentProofUrl')) {
    function getOfflinePaymentProofUrl(mixed $value, array $paths = []): ?string
    {
        if (!is_array($value) || empty($value['image_name'])) {
            return null;
        }

        $paths = $paths ?: [
            'offline-payment/order-proof',
            'offline-payment/activation-invoice-proof',
            'offline-payment/customer-package-proof',
            'seller-package/payment-proof',
            'seller-insurance/payment-proof',
        ];

        foreach ($paths as $path) {
            $storage = storageLink(trim($path, '/'), $value['image_name'], $value['storage'] ?? 'public');
            $url = is_array($storage) ? ($storage['path'] ?? null) : $storage;

            if ($url) {
                return $url;
            }
        }

        return null;
    }
}

if (!function_exists('translatePaymentText')) {
    function translatePaymentText(mixed $text): string
    {
        if (!is_scalar($text)) {
            return '';
        }

        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        $knownKeys = [
            'manual transfer / auto payment form' => 'manual_transfer_auto_payment_form',
            'transfer the amount then submit sender number and payment screenshot.' => 'Transfer_the_amount_then_submit_sender_number_and_payment_screenshot.',
            'sender wallet / phone number' => 'Sender_wallet_/_phone_number',
            'sender wallet or phone' => 'sender_wallet_or_phone',
            'sender name' => 'sender_name',
            'payment screenshot' => 'payment_screenshot',
            'payment proof' => 'payment_proof',
            'instructions' => 'instructions',
            'wallet_number' => 'wallet_number',
            'instapay_account' => 'instapay_account',
            'account_holder_name' => 'account_holder_name',
        ];

        return translate($knownKeys[strtolower($text)] ?? $text);
    }
}

if (!function_exists('offlinePaymentMethodDisplayName')) {
    function offlinePaymentMethodDisplayName(mixed $method): string
    {
        $channel = is_array($method) ? ($method['payment_channel'] ?? 'other') : ($method->payment_channel ?? 'other');
        $name = is_array($method) ? ($method['method_name'] ?? '') : ($method->method_name ?? '');
        $arabic = in_array(app()->getLocale(), ['ar', 'sa'], true);

        return match ($channel) {
            'wallet' => $arabic ? 'محفظة إلكترونية' : 'Electronic Wallet',
            'instapay' => $arabic ? 'إنستا باي' : 'InstaPay',
            default => translatePaymentText($name),
        };
    }
}
