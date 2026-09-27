<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Seller;

class SellerDashboardSetupService
{
    public function __construct(
        private readonly SellerRegistrationVerificationService $registrationVerificationService,
        private readonly SellerInsuranceService $sellerInsuranceService,
        private readonly SellerPackagePurchaseService $sellerPackagePurchaseService,
        private readonly SellerProductEntitlementService $sellerProductEntitlementService,
        private readonly SellerProductPromotionService $sellerProductPromotionService,
        private readonly SellerCommissionService $sellerCommissionService,
    ) {}

    public function getSummary(Seller $seller): array
    {
        $registration = $this->registrationVerificationService->getEligibility($seller);
        $insurance = $this->sellerInsuranceService->getSummary($seller);
        $package = $this->sellerPackagePurchaseService->getSummary($seller);
        $products = $this->sellerProductEntitlementService->getSummary($seller);
        $searchPromotions = $this->sellerProductPromotionService->getSearchSummary($seller);
        $homepagePromotions = $this->sellerProductPromotionService->getHomepageSummary($seller);
        $publishedProducts = Product::query()
            ->withoutGlobalScopes()
            ->where('added_by', 'seller')
            ->where('user_id', $seller->id)
            ->where('status', 1)
            ->where('request_status', 1)
            ->count();

        // Account activation is the only commercial gate for ordinary product
        // creation. Insurance is order-scoped and packages are advertising
        // products, so neither belongs to the seller onboarding checklist.
        $steps = [
            [
                'key' => 'account',
                // Login is intentionally available before activation. The
                // onboarding check must therefore reflect the activation
                // decision, not the ability to sign in.
                'completed' => ($registration['activation_status'] ?? null) === 'active',
                'state' => ($registration['activation_status'] ?? null) === 'active'
                    ? 'active'
                    : ($registration['next_step'] ?: 'await_activation_ticket'),
            ],
            [
                'key' => 'products',
                'completed' => $publishedProducts > 0,
                'state' => $publishedProducts > 0 ? 'published' : 'not_published',
            ],
        ];

        return [
            'registration' => $registration,
            'insurance' => $insurance,
            'package' => $package,
            'advertising_package_optional' => true,
            'products' => $products,
            'search_promotions' => $searchPromotions,
            'homepage_promotions' => $homepagePromotions,
            'commission' => $this->sellerCommissionService->getSummary($seller),
            'published_products_count' => $publishedProducts,
            'steps' => $steps,
            'completed_steps' => collect($steps)->where('completed', true)->count(),
            'completion_percentage' => (int) round(collect($steps)->where('completed', true)->count() / count($steps) * 100),
            'next_step' => $this->resolveNextStep(
                registration: $registration,
                publishedProducts: $publishedProducts,
            ),
        ];
    }

    private function resolveNextStep(
        array $registration,
        int $publishedProducts,
    ): string {
        if (($registration['activation_status'] ?? null) !== 'active') {
            return 'await_activation_ticket';
        }
        if (! $registration['can_login']) {
            return $registration['next_step'];
        }
        if ($publishedProducts === 0) {
            return 'add_first_product';
        }

        return 'manage_products';
    }
}
