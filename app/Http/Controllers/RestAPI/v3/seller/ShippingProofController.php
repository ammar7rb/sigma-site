<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderShippingProof;
use App\Services\OrderShippingProofService;
use App\Services\SellerOrderVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingProofController extends Controller
{
    public function index(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        abort_unless(app(SellerOrderVisibilityService::class)->canSellerView($order), 403);

        return response()->json([
            'order_id' => $order->id,
            'shipping_status' => $order->shipping_workflow_status,
            'proofs' => $order->shippingProofs()->latest()->get()->map(fn ($proof) => [
                'id' => $proof->id,
                'original_name' => $proof->original_name,
                'mime_type' => $proof->mime_type,
                'note' => $proof->note,
                'shipping_status' => $proof->shipping_status,
                'review_status' => $proof->review_status,
                'review_note' => $proof->review_note,
                'created_at' => $proof->created_at?->toISOString(),
            ]),
        ]);
    }

    public function store(Request $request, Order $order, OrderShippingProofService $service): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate([
            'shipping_status' => 'required|in:ready_to_ship,shipped,delivered',
            'proof' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,mp4,mov,avi,mkv,webm|max:102400',
            'note' => 'nullable|string|max:1000',
        ]);
        // The shared web completion workflow records its actor via the seller guard.
        // Scope that identity to this request; API authentication itself uses the bearer token.
        $guard = auth('seller');
        $previous = $guard->user();
        $guard->setUser($request->seller);
        try {
            $proof = $service->submit($order, (int) $request->seller->id, $data['shipping_status'], $data['proof'], $data['note'] ?? null);
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }

        return response()->json([
            'message' => $data['shipping_status'] === 'delivered'
                ? translate('delivery_proof_saved_order_completed') : translate('shipping_proof_uploaded_for_admin_review'),
            'order_status' => $order->fresh()->order_status,
            'proof_id' => $proof->id,
            'shipping_status' => $proof->shipping_status,
            'review_status' => $proof->review_status,
        ], 201);
    }

    public function download(Request $request, Order $order, OrderShippingProof $proof, OrderShippingProofService $service): mixed
    {
        $this->authorizeOrder($request, $order);
        abort_unless((int) $proof->order_id === (int) $order->id && (int) $proof->seller_id === (int) $request->seller->id, 404);
        abort_unless(app(SellerOrderVisibilityService::class)->canSellerView($order), 403);
        return $service->download($proof);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless($order->seller_is === 'seller' && (int) $order->seller_id === (int) $request->seller?->id, 403);
    }
}
