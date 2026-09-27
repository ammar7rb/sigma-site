<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerStockViolation;
use App\Services\SellerStockMonitoringService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SellerStockViolationController extends Controller
{
    public function __construct(private readonly SellerStockMonitoringService $stockMonitoringService)
    {
    }

    public function index(Request $request): View
    {
        $alerts = $this->stockMonitoringService->productsNeedingAttention($request->integer('seller_id') ?: null)
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = trim((string) $request->input('q'));
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->latest('updated_at')
            ->paginate((int) getWebConfig(name: 'pagination_limit'))
            ->withQueryString();

        $violations = SellerStockViolation::with(['seller', 'product', 'admin'])
            ->when($request->integer('seller_id'), fn ($query, $sellerId) => $query->where('seller_id', $sellerId))
            ->latest('decided_at')
            ->paginate((int) getWebConfig(name: 'pagination_limit'), ['*'], 'violations_page')
            ->withQueryString();

        return view('admin-views.vendor.stock-violations.index', compact('alerts', 'violations'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'seller_id' => 'required|integer',
            'order_id' => 'nullable|integer',
            'decision' => 'required|in:warning,penalty',
            'reason' => 'required|string|max:2000',
            'policy_reference' => 'nullable|string|max:120',
            'penalty_amount' => 'nullable|numeric|min:0.01',
        ]);

        $product = Product::withoutGlobalScopes()->find($validated['product_id']);
        $seller = Seller::find($validated['seller_id']);
        if (!$product || !$seller) {
            return back()->withErrors(['product_id' => translate('product_not_found')]);
        }

        try {
            $this->stockMonitoringService->recordManualDecision(
                product: $product,
                seller: $seller,
                decision: $validated['decision'],
                reason: $validated['reason'],
                adminId: auth('admin')->id(),
                penaltyAmount: isset($validated['penalty_amount']) ? (float) $validated['penalty_amount'] : null,
                policyReference: $validated['policy_reference'] ?? null,
                orderId: $validated['order_id'] ?? null,
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['decision' => translate($exception->getMessage())])->withInput();
        }

        return back()->with('success', translate('status_updated_successfully'));
    }
}
