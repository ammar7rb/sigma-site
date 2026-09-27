<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Order commerce flow rollout
    |--------------------------------------------------------------------------
    |
    | A switch is enabled only after its matching database, API, UI and tests
    | are delivered. Completed switches remain enabled by default.
    |
    */
    'contract_version' => '2026-08-v2',
    'legacy_flow_version' => 'legacy-v1',
    'new_flow_version' => 'admin-gated-v2',

    'features' => [
        'customer_insurance_after_purchase' => env('ORDER_FLOW_CUSTOMER_INSURANCE_AFTER_PURCHASE', true),
        'admin_review_before_seller' => env('ORDER_FLOW_ADMIN_REVIEW_BEFORE_SELLER', true),
        'seller_restricted_order_payload' => env('ORDER_FLOW_SELLER_RESTRICTED_PAYLOAD', false),
        'insurance_only_balances' => env('ORDER_FLOW_INSURANCE_ONLY_BALANCES', true),
        'advertising_packages_only' => env('ORDER_FLOW_ADVERTISING_PACKAGES_ONLY', false),
        'promotion_requires_admin_approval' => env('ORDER_FLOW_PROMOTION_ADMIN_APPROVAL', false),
        'homepage_section_builder' => env('ORDER_FLOW_HOMEPAGE_SECTION_BUILDER', false),
        'admin_support_only_communications' => env('ORDER_FLOW_ADMIN_SUPPORT_ONLY_COMMUNICATIONS', false),
    ],

    'customer_insurance' => [
        // The real value remains admin-managed. This value is only a safe
        // fallback for the future post-purchase insurance deadline.
        'payment_deadline_hours' => (int) env('CUSTOMER_INSURANCE_PAYMENT_DEADLINE_HOURS', 72),
        'purchase_refund_delay_days' => (int) env('CUSTOMER_PURCHASE_REFUND_DELAY_DAYS', 7),
        'allowed_balance_uses' => ['insurance_payment'],
    ],

    'seller_insurance' => [
        'allowed_balance_uses' => ['insurance_payment'],
        'allowed_direct_payment_methods' => ['digital_payment', 'offline_payment'],
    ],

    'purchase_wallet' => [
        'allowed_uses' => ['product_purchase'],
    ],
];
