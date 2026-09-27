<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderShippingProof;
use App\Models\OrderRefundLedger;
use App\Services\OrderShippingProofService;
use App\Services\PostPaymentRefundService;
use App\Services\ShippingPromiseService;
use Carbon\Carbon;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingOperationsController extends Controller
{
    public function deliveryProofs(Request $request): View
    {
        $request->validate([
            'review_status' => 'nullable|in:pending,approved,rejected',
            'search' => 'nullable|string|max:100',
        ]);

        $summaryQuery = OrderShippingProof::query();
        $summary = [
            'all' => (clone $summaryQuery)->count(),
            'pending' => (clone $summaryQuery)->where('review_status', 'pending')->count(),
            'approved' => (clone $summaryQuery)->where('review_status', 'approved')->count(),
            'rejected' => (clone $summaryQuery)->where('review_status', 'rejected')->count(),
        ];

        $query = OrderShippingProof::query()
            ->with(['order:id,seller_id,order_status,shipping_workflow_status', 'seller:id,f_name,l_name'])
            ->when($request->filled('review_status'), fn ($builder) => $builder->where('review_status', $request->string('review_status')))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $term = trim((string) $request->input('search'));
                $builder->where(function ($nested) use ($term) {
                    $nested->where('order_id', $term)
                        ->orWhere('original_name', 'like', "%{$term}%")
                        ->orWhereHas('seller', fn ($seller) => $seller
                            ->where('f_name', 'like', "%{$term}%")
                            ->orWhere('l_name', 'like', "%{$term}%"));
                });
            });

        $proofs = $query->latest('id')->paginate(30)->withQueryString();

        return view('admin-views.order.delivery-proofs.index', compact('proofs', 'summary'));
    }

    public function refunds(Request $request): View
    {
        $request->validate([
            'status' => 'nullable|in:held,available',
            'search' => 'nullable|string|max:100',
        ]);

        $query = OrderRefundLedger::query()
            ->with(['order:id,customer_id,order_amount,order_status', 'customer:id,f_name,l_name,email,phone', 'admin:id,name'])
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $term = trim((string) $request->input('search'));
                $builder->where(function ($nested) use ($term) {
                    $nested->where('order_id', $term)
                        ->orWhere('customer_id', $term)
                        ->orWhere('idempotency_key', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('email', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"));
                });
            });

        $summary = [
            'count' => (clone $query)->count(),
            'purchase_amount' => (float) (clone $query)->sum('purchase_amount'),
            'insurance_amount' => (float) (clone $query)->sum('insurance_amount'),
            'held_count' => (clone $query)->where('status', 'held')->count(),
        ];
        $refunds = $query->latest('id')->paginate(30)->withQueryString();

        return view('admin-views.order.post-payment-refunds.index', compact('refunds', 'summary'));
    }

    public function allocation(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'seller_shipping_allocation' => 'required|numeric|min:0',
            'shipping_settlement_due_at' => 'required|date',
            'reason' => 'required|string|max:1000',
        ]);

        $allocation = min((float) $data['seller_shipping_allocation'], (float) $order->shipping_cost);
        $order->forceFill([
            'seller_shipping_allocation' => $allocation,
            'platform_shipping_margin' => max(0, (float) $order->shipping_cost - $allocation),
        ])->save();
        app(ShippingPromiseService::class)->updateSettlementDueDate(
            $order,
            Carbon::parse($data['shipping_settlement_due_at']),
            $data['reason'],
            (int) auth('admin')->id()
        );

        ToastMagic::success(translate('shipping_allocation_and_due_date_updated'));
        return back();
    }

    public function stockOutRefund(Request $request, Order $order, PostPaymentRefundService $service): RedirectResponse
    {
        $data = $request->validate([
            'purchase_amount' => 'required|numeric|min:0.01',
            'insurance_amount' => 'nullable|numeric|min:0',
            'available_at' => 'nullable|date|after_or_equal:today',
            'note' => 'required|string|max:1000',
        ]);

        $service->refundStockOut(
            $order,
            (float) $data['purchase_amount'],
            (float) ($data['insurance_amount'] ?? 0),
            (int) auth('admin')->id(),
            $data['note'],
            isset($data['available_at']) ? Carbon::parse($data['available_at']) : null
        );

        ToastMagic::success(translate('refund_was_split_between_purchase_and_insurance_balances'));
        return back();
    }

    public function reviewProof(Request $request, OrderShippingProof $proof, OrderShippingProofService $service): RedirectResponse
    {
        $data = $request->validate([
            'review_status' => 'required|in:approved,rejected',
            'review_note' => 'nullable|string|max:1000',
        ]);
        $service->review($proof, $data['review_status'], (int) auth('admin')->id(), $data['review_note'] ?? null);

        ToastMagic::success(translate('shipping_proof_reviewed'));
        return back();
    }

    public function downloadProof(OrderShippingProof $proof, OrderShippingProofService $service): mixed
    {
        return $service->download($proof);
    }

    public function previewProof(OrderShippingProof $proof, OrderShippingProofService $service): mixed
    {
        return $service->preview($proof);
    }
}
