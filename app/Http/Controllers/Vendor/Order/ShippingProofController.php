<?php

namespace App\Http\Controllers\Vendor\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderShippingProofService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShippingProofController extends Controller
{
    public function store(Request $request, Order $order, OrderShippingProofService $service): RedirectResponse
    {
        $data = $request->validate([
            'shipping_status' => 'required|in:ready_to_ship,shipped,delivered',
            'proof' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,mp4,mov,avi,mkv,webm|max:102400',
            'note' => 'nullable|string|max:1000',
        ]);
        $service->submit($order, (int) auth('seller')->id(), $data['shipping_status'], $data['proof'], $data['note'] ?? null);

        ToastMagic::success($data['shipping_status'] === 'delivered'
            ? translate('delivery_proof_saved_order_completed') : translate('shipping_proof_uploaded_for_admin_review'));
        return back();
    }
}
