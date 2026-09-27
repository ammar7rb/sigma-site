<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartShipping;
use App\Models\ShippingMethod;
use App\Models\ShippingType;
use App\Models\ShippingAddress;
use App\Services\CustomerThreeStepShippingService;
use App\Utils\CartManager;
use App\Utils\Helpers;
use App\Utils\OrderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShippingMethodController extends Controller
{
    public function get_shipping_method_info($id)
    {
        try {
            $shipping = ShippingMethod::find($id);
            return response()->json($shipping, 200);
        } catch (\Exception $e) {
            return response()->json(['errors' => $e], 403);
        }
    }

    public function shipping_methods_by_seller(Request $request, $id, $seller_is, CustomerThreeStepShippingService $shippingService)
    {
        $seller_is = $seller_is == 'admin' ? 'admin' : 'seller';
        if ($shippingService->isEnabled()) {
            return response()->json($this->formatThreeStepOptions($shippingService), 200);
        }
        return response()->json(Helpers::getShippingMethods($id, $seller_is), 200);
    }

    public function choose_for_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cart_group_id' => 'required',
            'id' => 'required'
        ], [
            'id.required' => translate('shipping_id_is_required')
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        $selected = true;
        if ($request['cart_group_id'] == 'all_cart_group') {
            foreach (CartManager::get_cart_group_ids(request: $request) as $group_id) {
                $request['cart_group_id'] = $group_id;
                $selected = self::insert_into_cart_shipping($request) && $selected;
            }
        } else {
            $selected = self::insert_into_cart_shipping($request);
        }

        if (!$selected) {
            return response()->json([
                'errors' => [[
                    'code' => 'shipping_method_unavailable',
                    'message' => translate('selected_shipping_method_is_not_available_now'),
                ]],
            ], 403);
        }

        return response()->json(translate('successfully_added'));
    }

    public static function insert_into_cart_shipping($request): bool
    {
        $shipping = CartShipping::where(['cart_group_id' => $request['cart_group_id']])->first();
        if (isset($shipping) == false) {
            $shipping = new CartShipping();
        }
        $method = app(CustomerThreeStepShippingService::class)->resolveSelectableMethod($request['id']);
        if (!$method) {
            return false;
        }
        $shippingService = app(CustomerThreeStepShippingService::class);
        $shippingAddress = $request->filled('address_id')
            ? ShippingAddress::find($request->input('address_id'))
            : null;
        $shippingCost = $shippingService->isEnabled()
            ? $shippingService->calculateCartGroupCost($request['cart_group_id'], $method, $shippingAddress)
            : $method->cost;
        if ($shippingCost === null) {
            // Do not allow checkout until every physical product has an admin shipping rate.
            return false;
        }
        $shipping['cart_group_id'] = $request['cart_group_id'];
        $shipping['shipping_method_id'] = $method->id;
        $shipping['shipping_cost'] = $shippingCost;
        $shipping->save();

        return true;
    }

    /** Returns both delivery prices; it never selects a method on behalf of the customer. */
    public function quote_for_address(Request $request, CustomerThreeStepShippingService $shippingService): JsonResponse
    {
        $validator = Validator::make($request->all(), ['address_id' => ['required', 'integer']]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $customer = Helpers::getCustomerInformation($request);
        $address = ShippingAddress::query()
            ->where('id', $request->integer('address_id'))
            ->when($customer === 'offline', fn ($query) => $query->where([
                'customer_id' => $request->guest_id,
                'is_guest' => 1,
            ]))
            ->when($customer !== 'offline', fn ($query) => $query->where([
                'customer_id' => $customer->id,
                'is_guest' => 0,
            ]))
            ->first();

        if (!$address) {
            return response()->json(['errors' => [[
                'code' => 'address_not_found',
                'message' => translate('address_not_found'),
            ]]], 404);
        }

        $cartGroups = [];
        $totals = ['normal' => 0.0, 'sigma' => 0.0];
        $definitions = [];
        $groupIds = CartManager::get_cart_group_ids(request: $request, type: 'checked');
        // An address change invalidates any old price and choice. The new quote
        // remains only an offer until the customer taps Normal or Sigma.
        CartShipping::whereIn('cart_group_id', $groupIds)->delete();
        foreach ($groupIds as $groupId) {
            if (!Cart::where('cart_group_id', $groupId)->where('is_checked', 1)->where('product_type', 'physical')->exists()) {
                continue;
            }

            $quote = $shippingService->quoteAutomaticDistanceDeliveryOptions($groupId, $address);
            if (!$quote) {
                return response()->json(['errors' => [[
                    'code' => 'shipping_location_required',
                    'message' => 'تعذر حساب الشحن لهذا العنوان. راجع المحافظة وتفاصيل العنوان.',
                ]]], 403);
            }

            $groupOptions = [];
            foreach ($quote['options'] as $optionKey => $option) {
                $totals[$optionKey] += $option['cost'];
                $definitions[$optionKey] ??= $option;
                $groupOptions[$optionKey] = [
                    'method_id' => $option['method']->id,
                    'shipping_cost' => $option['cost'],
                ];
            }
            $cartGroups[] = [
                'cart_group_id' => $groupId,
                'distance_km' => $quote['distance_km'],
                'options' => $groupOptions,
            ];
        }

        if (!$cartGroups) {
            return response()->json(['cart_groups' => [], 'options' => []], 200);
        }

        return response()->json([
            'address_id' => $address->id,
            'cart_groups' => $cartGroups,
            'options' => collect(['normal', 'sigma'])->map(function (string $optionKey) use ($definitions, $totals) {
                $option = $definitions[$optionKey];
                return [
                    'key' => $optionKey,
                    'method_id' => $option['method']->id,
                    'title' => $option['method']->title,
                    'duration' => $option['method']->duration,
                    'shipping_cost' => round($totals[$optionKey], 3),
                    'minimum_business_days' => $option['promise']['min_days'],
                    'maximum_business_days' => $option['promise']['max_days'],
                    'estimated_label' => $option['promise']['display_date'],
                ];
            })->values(),
        ], 200);
    }

    /** Persists the explicitly selected quote for every physical cart group. */
    public function select_quote_for_address(Request $request, CustomerThreeStepShippingService $shippingService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'address_id' => ['required', 'integer'],
            'option' => ['required', 'in:normal,sigma'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $customer = Helpers::getCustomerInformation($request);
        $address = ShippingAddress::query()
            ->where('id', $request->integer('address_id'))
            ->when($customer === 'offline', fn ($query) => $query->where(['customer_id' => $request->guest_id, 'is_guest' => 1]))
            ->when($customer !== 'offline', fn ($query) => $query->where(['customer_id' => $customer->id, 'is_guest' => 0]))
            ->first();
        if (!$address) {
            return response()->json(['message' => translate('address_not_found')], 404);
        }

        $selected = [];
        foreach (CartManager::get_cart_group_ids(request: $request, type: 'checked') as $groupId) {
            if (!Cart::where('cart_group_id', $groupId)->where('is_checked', 1)->where('product_type', 'physical')->exists()) {
                continue;
            }
            $quote = $shippingService->quoteAutomaticDistanceDeliveryOptions($groupId, $address);
            $option = is_array($quote) ? ($quote['options'][(string) $request->input('option')] ?? null) : null;
            if (!$option) {
                return response()->json(['message' => translate('selected_shipping_method_is_not_available_now')], 403);
            }
            $shipping = CartShipping::updateOrCreate(
                ['cart_group_id' => $groupId],
                ['shipping_method_id' => $option['method']->id, 'shipping_cost' => $option['cost']]
            );
            $selected[] = $shipping;
        }

        return response()->json(['selected' => $selected], 200);
    }

    public function chosen_shipping_methods(Request $request, CustomerThreeStepShippingService $shippingService): JsonResponse
    {
        $groupIds = CartManager::get_cart_group_ids(request: $request);
        $cartShipping = CartShipping::whereIn('cart_group_id', $groupIds)->get();

        // Quotes are only created by quote-for-address after the customer has
        // selected a governorate and entered a structured text address.

        $cartShipping->map(function ($data) {
            $isCheckedItemExist = Cart::where(['cart_group_id' => $data['cart_group_id'], 'is_checked' => 1])->exists();
            $freeDeliveryStatus = OrderManager::getFreeDeliveryOrderAmountArray($data['cart_group_id'])['status'];
            $data['free_delivery_status'] = $freeDeliveryStatus;
            $data['is_check_item_exist'] = $isCheckedItemExist ? 1 : 0;
            return $data;
        });

        if ($request->boolean('include_three_step_options')) {
            return response()->json([
                'chosen' => $cartShipping,
                'three_step_shipping' => [
                    'enabled' => $shippingService->isEnabled(),
                    'same_day_cutoff' => $shippingService->getSameDayCutoff(),
                    'options' => $this->formatThreeStepOptions($shippingService),
                ],
            ], 200);
        }

        return response()->json($cartShipping, 200);
    }

    public function three_step_options(CustomerThreeStepShippingService $shippingService): JsonResponse
    {
        return response()->json([
            'enabled' => $shippingService->isEnabled(),
            'same_day_cutoff' => $shippingService->getSameDayCutoff(),
            'options' => $this->formatThreeStepOptions($shippingService),
        ], 200);
    }

    private function formatThreeStepOptions(CustomerThreeStepShippingService $shippingService): array
    {
        return $shippingService->getThreeStepOptionsWithAvailability()
            ->map(function (array $option) {
                $promise = app(\App\Services\ShippingPromiseService::class)
                    ->promise($option['option_key']);

                return $option + [
                    'promise_mode' => $promise['mode'],
                    'minimum_business_days' => $promise['min_days'],
                    'maximum_business_days' => $promise['max_days'],
                    'estimated_from' => $promise['eta_from'],
                    'estimated_to' => $promise['eta_to'],
                    'estimated_label' => $promise['display_date'],
                ];
            })
            ->all();
    }

    public function check_shipping_type(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'seller_is' => 'required',
            'seller_id' => 'required'
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        if($request->seller_is == 'admin')
        {
            $admin_shipping = ShippingType::where('seller_id',0)->first();
            $shipping_type = isset($admin_shipping)==true?$admin_shipping->shipping_type:'order_wise';

        }
        else{
            $seller_shipping = ShippingType::where('seller_id',$request->seller_id)->first();
            $shipping_type = isset($seller_shipping)==true? $seller_shipping->shipping_type:'order_wise';

        }
        return response()->json(['shipping_type'=>$shipping_type], 200);
    }
}
