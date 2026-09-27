<?php

namespace App\Services;

use App\Http\Controllers\Vendor\Order\OrderController;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SellerDeliveryCompletionService
{
    public function complete(Order $order): void
    {
        // Reuse the established delivery workflow, including payment checks,
        // inventory, settlement, rewards, notifications and status history.
        $request = Request::create('/', 'POST', ['id' => $order->id, 'order_status' => 'delivered']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $response = app()->call([app(OrderController::class), 'updateStatus'], ['request' => $request]);
        $result = $response->getData(true);
        if (($result['status'] ?? 0) != 1) {
            throw ValidationException::withMessages(['proof' => $result['message'] ?? translate('delivery_completion_failed')]);
        }
    }
}
