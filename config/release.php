<?php

return [
    'mobile_workspace' => env('MOBILE_APPS_WORKSPACE', 'E:\\project'),
    'backup_reference' => env('RELEASE_BACKUP_REFERENCE'),
    'approved_by' => env('RELEASE_APPROVED_BY'),
    'open_requirements' => [
        'T1-04' => 'Password recovery must be replaced by the verified support-ticket workflow.',
        'T2-05' => 'Seller profile synchronization and alternate pickup address are not closed yet.',
    ],
    'critical_schedules' => [
        'products:enforce-expiry',
        'packages:expire-subscriptions',
        'seller-settlements:release-due',
        'customer-insurance:release-due',
        'seller-order-insurances:release-reusable',
        'orders:monitor-seller-delays',
        'orders:expire-customer-confirmations',
    ],
    'forbidden_route_fragments' => [
        'purchase-package',
        'purchase-packages',
        'activation-invoice',
    ],
    'payment_hooks' => [
        'digital_payment_success',
        'digital_payment_fail',
        'seller_package_payment_success',
        'seller_package_payment_fail',
        'seller_balance_deposit_payment_success',
        'seller_balance_deposit_payment_fail',
        'seller_order_insurance_payment_success',
        'seller_order_insurance_payment_fail',
    ],
    'required_schema' => [
        'products' => ['production_date', 'expiry_date', 'seller_review_status'],
        'seller_package_subscriptions' => ['duration_unit', 'duration_value', 'search_priority'],
        'order_insurances' => ['matures_at', 'rule_snapshot', 'payment_due_at', 'balance_use_policy', 'purchase_refund_status', 'confiscation_status'],
        'seller_order_insurances' => ['reusable_at', 'rule_snapshot', 'amount_source', 'balance_use_policy', 'confiscation_status'],
        'insurance_balance_actions' => ['subject_type', 'subject_id', 'action', 'amount', 'reference', 'reason', 'admin_id'],
        'seller_product_promotions' => ['approval_status', 'placement_scope', 'approved_position'],
        'seller_settlements' => ['due_at', 'timezone_snapshot', 'pre_due_days_snapshot'],
        'orders' => ['shipment_reference', 'customer_delivery_confirmation_due_at', 'commerce_flow_version', 'commerce_flow_status', 'admin_order_review_status'],
        'release_data_migration_runs' => ['batch_key', 'status', 'summary'],
        'release_data_migration_items' => ['action', 'before_values', 'after_values'],
    ],
];
