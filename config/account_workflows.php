<?php

return [
    'activation_center' => [
        'enabled' => (bool) env('ACTIVATION_CENTER_ENABLED', true),
        'private_disk' => env('ACTIVATION_DOCUMENT_DISK', 'local'),
        'encrypt_documents' => (bool) env('ACTIVATION_DOCUMENT_ENCRYPTION_ENABLED', true),
        'max_document_size_kb' => (int) env('ACTIVATION_DOCUMENT_MAX_SIZE_KB', 10240),
        'allowed_document_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'],
    ],

    'commerce_contract' => [
        'current_version' => env('COMMERCE_CONTRACT_VERSION', 'post_purchase_v3'),
        // Opt-in while the deployment is migrated. Legacy rows stay on
        // legacy_v1 and are never recalculated after activation.
        'post_purchase_tax_invoice_enabled' => (bool) env('POST_PURCHASE_TAX_INVOICE_ENABLED', false),
        'configurable_shipping_promises_enabled' => (bool) env('CONFIGURABLE_SHIPPING_PROMISES_ENABLED', false),
    ],
];
