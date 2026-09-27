<?php

return [
    'journey' => [
        'seller_registration' => 'tests/Unit/SellerActivationRegistrationApiTest.php',
        'seller_activation' => 'tests/Unit/SellerActivationServiceTest.php',
        'seller_package_purchase' => 'tests/Unit/SellerPackagePurchaseServiceTest.php',
        'product_five_images_and_dates' => 'tests/Unit/SellerProductSpecificationServiceTest.php',
        'product_review_and_publish' => 'tests/Unit/SellerProductReviewServiceTest.php',
        'customer_activation' => 'tests/Unit/IdentityAndSupportBatchTest.php',
        'customer_order_insurance' => 'tests/Unit/InsuranceRulesAndIncentivesTest.php',
        'shipping_quote_and_decision' => 'tests/Unit/OrderOperationsBatchTest.php',
        'seller_order_insurance' => 'tests/Unit/SellerOrderInsuranceServiceTest.php',
        'delivery_and_customer_confirmation' => 'tests/Unit/OrderLogisticsServiceTest.php',
        'settlement_and_release' => 'tests/Unit/SellerSettlementServiceTest.php',
        'withdrawal_method' => 'tests/Unit/SellerWithdrawalMethodServiceTest.php',
        'return_and_financial_reversal' => 'tests/Unit/OrderOperationsBatchTest.php',
        'financial_reconciliation' => 'tests/Unit/AdminReportingBatchTest.php',
        'vendor_and_customer_app_contracts' => 'tests/Unit/MobileApplicationsSynchronizationTest.php',
    ],
];
