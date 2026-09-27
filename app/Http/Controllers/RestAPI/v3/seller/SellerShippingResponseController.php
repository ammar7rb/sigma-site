<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SellerShippingResponseService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerShippingResponseController extends Controller
{
    public function __construct(private readonly SellerShippingResponseService $responses) {}

    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $order = Order::query()->findOrFail($id);

        try {
            $saved = $data['decision'] === 'accept'
                ? $this->responses->accept($order, $request->seller, $data['reason'] ?? null)
                : $this->responses->reject($order, $request->seller, (string) ($data['reason'] ?? ''));
            return response()->json(['message' => translate('seller_shipping_response_saved'), 'order' => $this->payload($saved)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
    }

    private function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_status' => $order->order_status,
            'commerce_flow_status' => $order->commerce_flow_status,
            'seller_shipping_response_status' => $order->seller_shipping_response_status,
            'seller_shipping_response_due_at' => $order->seller_shipping_response_due_at?->toIso8601String(),
            'shipping_workflow_status' => $order->shipping_workflow_status,
        ];
    }
}
