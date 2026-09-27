<?php

namespace App\Http\Controllers\Vendor\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SellerShippingResponseService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SellerShippingResponseController extends Controller
{
    public function __construct(private readonly SellerShippingResponseService $responses) {}

    public function store(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $data['decision'] === 'accept'
                ? $this->responses->accept($order, auth('seller')->user(), $data['reason'] ?? null)
                : $this->responses->reject($order, auth('seller')->user(), (string) ($data['reason'] ?? ''));
            ToastMagic::success(translate('seller_shipping_response_saved'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }
}
