<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Events\OrderStatusEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v3\DigitalProductFileUploadAfterSell;
use App\Models\BusinessSetting;
use App\Models\DeliveryManTransaction;
use App\Models\DeliverymanWallet;
use App\Models\DeliveryZipCode;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderEditHistory;
use App\Models\ReferralCustomer;
use App\Traits\CommonTrait;
use App\Models\User;
use App\Traits\ProductTrait;
use App\Utils\BackEndHelper;
use App\Utils\Convert;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use App\Utils\ImageManager;
use App\Utils\OrderManager;
use App\Services\OrderLogisticsService;
use App\Services\OrderWorkflowService;
use App\Services\SellerOrderInsuranceService;
use App\Services\OrderCommerceContractService;
use App\Support\Commerce\OrderCommerceState;
use DomainException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;


class OrderController extends Controller
{
    use CommonTrait;
    use ProductTrait;

    public function __construct(
        private DeliveryZipCode                   $delivery_zip_code,
        private Order                             $order,
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly SellerOrderInsuranceService $sellerOrderInsuranceService,
    )
    {
    }

    public function list(Request $request): JsonResponse
    {
        $seller = $request->seller;
        app(OrderWorkflowService::class)->refreshSellerQueue((int) $seller['id']);
        $status = $request->status;

        $dateType = $request['date_type'];
        $paymentPaidStatus = json_decode($request['payment_status'] ?? '', true);
        $orderStatus = json_decode($request['order_current_status'] ?? '', true);

        $filters = [
            'filter' => $request['filter'] ?? 'all',
            'date_type' => $request['date_type'],
            'from' => $request['start_date'],
            'to' => $request['end_date'],
            'delivery_man_id' => $request['delivery_man_id'],
            'customer_id' => $request['customer_id'],
            'seller_id' => $seller['id'],
            'seller_is' => 'seller',
            'seller_visible_only' => true,
            'exclude_seller_insurance_pending' => $status !== 'all',
            'seller_disputed_only' => $request->boolean('seller_disputed_only'),
            'whereIn_order_status' => $orderStatus,
        ];
        $orderAmountSettlement = json_decode($request['order_amount_settlement'] ?? '');

        if (!empty($orderAmountSettlement)) {
            $filters['has_order_edit_settlement'] = $orderAmountSettlement;
        }

        $filterWhereIn = [];
        if (!empty($paymentPaidStatus)) {
            $filterWhereIn['payment_status'] = $paymentPaidStatus;
        }

        $orderTypes = json_decode($request['order_types'] ?? '', true);
        if (!empty($orderTypes)) {
            $filterWhereIn['order_type'] = $orderTypes;
        }

        $orders = $this->orderRepo->getListWhereIn(
            orderBy: ['id' => 'desc'],
            searchValue: $request['search_value'],
            filters: $filters,
            whereIn: $filterWhereIn,
            relations: ['customer', 'shipping', 'deliveryMan', 'orderDetails', 'offlinePayments'],
            dataLimit: $request['limit'],
            offset: $request['offset'],
        );

        $orders->setCollection($orders->getCollection()->sortBy(
            fn ($order) => in_array($order->order_status, OrderWorkflowService::ACTIVE_QUEUE_STATUSES, true)
                ? (int) ($order->seller_queue_position ?? PHP_INT_MAX)
                : PHP_INT_MAX
        )->values());
        $orders->setCollection($orders->getCollection()->map(function ($data) {
            if ($this->mustReturnRestrictedOrder($data)) {
                $data->loadMissing('sellerOrderInsurance');
                return app(OrderCommerceContractService::class)->restrictedSellerPayload($data, $data->sellerOrderInsurance);
            }
            $this->sanitizeShippingDecisionForSeller($data);
            $data->setAttribute('shipping_decision_required', ($data->shipping_assignment_status ?? 'pending') !== 'assigned');
            $data->setAttribute('queue_state', app(OrderWorkflowService::class)->queueState($data));
            if (isset($data['offlinePayments'])) {
                $data['offlinePayments']->payment_info = $data->offlinePayments->payment_info;
            }

            $totalTaxAmount = 0;
            $totalProductPrice = 0;
            $totalProductDiscount = 0;
            if (isset($data['orderDetails']) && count($data['orderDetails']) > 0) {
                $totalTaxAmount = $data['orderDetails']->sum('tax');
                $totalProductPrice = $data['orderDetails']->sum('price');
                $totalProductDiscount = $data['orderDetails']->sum('discount');
            }
            $data['total_tax_amount'] = $totalTaxAmount;
            $data['total_product_price'] = $totalProductPrice;
            $data['total_product_discount'] = $totalProductDiscount;
            return $data;
        }));

        return response()->json([
            'total_size' => $orders->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'orders' => $orders->items()
        ], 200);
    }

    /**
     * Latest orders for the seller dashboard/order inbox.
     */
    public function recent(Request $request): JsonResponse
    {
        return $this->sellerOrderWindowResponse($request, false);
    }

    /**
     * Current-month order history for the seller. Older records remain available
     * to administration through the existing order APIs and reports.
     */
    public function history(Request $request): JsonResponse
    {
        return $this->sellerOrderWindowResponse($request, true);
    }

    /** A dedicated inbox that never selects or serializes customer, address or product relations. */
    public function insurancePending(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $limit = min(max((int) $request->input('limit', 10), 1), 50);
        $page = max((int) $request->input('offset', 1), 1);
        $orders = Order::query()
            ->select(['id', 'seller_id', 'seller_is', 'order_amount', 'commerce_flow_version', 'commerce_flow_status', 'created_at'])
            ->with('sellerOrderInsurance')
            ->where(['seller_id' => $seller->id, 'seller_is' => 'seller'])
            ->whereIn('commerce_flow_version', [config('order_commerce.new_flow_version'), \App\Services\PostPurchaseInvoiceService::CONTRACT_VERSION])
            ->whereIn('commerce_flow_status', [OrderCommerceState::SELLER_INSURANCE_PENDING, OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW])
            ->latest('id')->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'total_size' => $orders->total(), 'limit' => $limit, 'offset' => $page,
            'orders' => $orders->getCollection()->map(fn (Order $order) => app(OrderCommerceContractService::class)
                ->restrictedSellerPayload($order, $order->sellerOrderInsurance))->values(),
        ]);
    }

    private function sellerOrderWindowResponse(Request $request, bool $currentMonth): JsonResponse
    {
        $seller = $request->seller;
        app(OrderWorkflowService::class)->refreshSellerQueue((int) $seller['id']);
        $limit = min(max((int) ($request->input('limit', 10) ?: 10), 1), 50);
        $offset = max((int) ($request->input('offset', 1) ?: 1), 1);

        $filters = [
            'seller_id' => $seller['id'],
            'seller_is' => 'seller',
            'seller_visible_only' => true,
            'exclude_seller_insurance_pending' => true,
        ];
        if ($currentMonth) {
            $filters['date_type'] = 'this_month';
        }

        $orders = $this->orderRepo->getListWhereIn(
            orderBy: ['id' => 'desc'],
            searchValue: $request->input('search_value'),
            filters: $filters,
            relations: ['customer', 'shipping', 'deliveryMan', 'orderDetails', 'offlinePayments'],
            dataLimit: $limit,
            offset: $offset,
        );

        $orders->setCollection($orders->getCollection()->map(function ($order) {
            if ($this->mustReturnRestrictedOrder($order)) {
                $order->loadMissing('sellerOrderInsurance');
                return app(OrderCommerceContractService::class)->restrictedSellerPayload($order, $order->sellerOrderInsurance);
            }
            $this->sanitizeShippingDecisionForSeller($order);
            $order->setAttribute('shipping_decision_required', ($order->shipping_assignment_status ?? 'pending') !== 'assigned');
            $order->setAttribute('queue_state', app(OrderWorkflowService::class)->queueState($order));
            return $order;
        }));

        $total = $orders->total();
        return response()->json([
            'total_size' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'period' => $currentMonth ? 'current_month' : 'recent',
            'orders' => $orders->items(),
            'empty_message' => $total === 0 ? translate('no_order_found') : null,
        ], 200);
    }

    public function details(Request $request, $id): JsonResponse
    {
        $seller = $request->seller;
        $order = $this->resolveSellerOrderReference($seller->id, (string) $id);
        if (! $order) return response()->json(['message' => translate('Order_not_found')], 404);
        $id = $order->id;
        if (in_array($order->admin_order_review_status, ['pending_admin_review', 'assignment_in_progress', 'waiting_customer_post_purchase_payment'], true)) {
            return response()->json(['message' => translate('Order_not_found')], 404);
        }
        if (in_array($order->commerce_flow_version, [config('order_commerce.new_flow_version'), \App\Services\PostPurchaseInvoiceService::CONTRACT_VERSION], true)
            && ! in_array($order->commerce_flow_status, [
                OrderCommerceState::SELLER_INSURANCE_PENDING,
                OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW,
                OrderCommerceState::RELEASED_TO_SELLER,
                OrderCommerceState::FULFILLMENT_IN_PROGRESS,
                OrderCommerceState::COMPLETED,
                OrderCommerceState::CANCELLED,
            ], true)) {
            return response()->json(['message' => translate('Order_not_found')], 404);
        }
        try {
            $insurance = $this->sellerOrderInsuranceService->getOrCreate($order, $seller);
            if ($insurance && ! $this->sellerOrderInsuranceService->canViewDetails($insurance)) {
                return response()->json([
                    'message' => translate('seller_order_insurance_payment_required_before_order_details'),
                    'restricted_order' => app(OrderCommerceContractService::class)->restrictedSellerPayload($order, $insurance),
                ], 423);
            }
        } catch (DomainException $exception) {
            return response()->json(['message' => translate($exception->getMessage())], 422);
        }
        $detailsList = OrderDetail::with(['order.offlinePayments', 'order.customer', 'order.deliveryMan', 'verificationImages', 'latestEditHistory', 'orderEditHistory' => function ($query) {
            return $query->orderBy('updated_at', 'desc');
        }])->where(['seller_id' => $seller['id'], 'order_id' => $id])->get();

        $productList = $this->getProductListWithAllDetails(ids: $detailsList?->pluck('product_id')->toArray());

        $orderEditPaymentHistory = OrderEditHistory::where('order_id', $id)->orderBy('id', 'asc')->get();
        $filteredEditPaymentHistory = $orderEditPaymentHistory->filter(function ($item) {
            return $item->order_due_payment_status === 'paid'
                || $item->order_return_payment_status === 'returned';
        });

        $latestHistory = $orderEditPaymentHistory->sortByDesc('updated_at')->first();
        $unpaidDue = [];
        if ($latestHistory && $latestHistory->order_due_payment_status !== 'paid' && $latestHistory->order_due_amount > 0) {
            $unpaidDue[] = $latestHistory;
        }
        $paymentInfo = collect()->merge($filteredEditPaymentHistory)->merge($unpaidDue)->values();

        $firstDetails = $detailsList->first();
        if ($firstDetails && $firstDetails->order && $firstDetails->init_order_amount <= 0) {
            Order::where('id', $firstDetails->order?->id)->update(['init_order_amount' => $firstDetails->order['order_amount']]);
        }

        foreach ($detailsList as $detail) {
            $product = json_decode($detail['product_details'], true);

            if (!isset($product['digital_variation'])) {
                $product['digital_variation'] = [];
            }

            $product['thumbnail_full_url'] = $detail?->productAllStatus?->thumbnail_full_url;
            if (isset($product['product_type']) && $product['product_type'] == 'digital' && $product['digital_product_type'] == 'ready_product' && $product['digital_file_ready']) {
                $checkFilePath = storageLink('product/digital-product', $product['digital_file_ready'], ($product['storage_path'] ?? 'public'));
                $product['digital_file_ready_full_url'] = $checkFilePath;
            }
            $detail['product_details'] = Helpers::product_data_formatting_for_json_data($product);


            $detailsVariation = is_array($detail['variation']) ? $detail['variation'] : json_decode($detail['variation'] ?? '', true);
            $modifiedVariation = [];
            if (count($detailsVariation) > 0) {
                foreach ($detailsVariation as $variationKey => $variation) {
                    $modifiedVariation[] = [
                        'key' => $variationKey,
                        'value' => $variation,
                    ];
                }
            }

            $detail['variation'] = $modifiedVariation;
            $detail['modified_variation'] = $modifiedVariation;

            $activeProduct = $productList?->firstWhere('id', $detail['product_id']) ?? $product;
            $unitPrice = $activeProduct ? $activeProduct['unit_price'] : $detail['price'];
            $currentStock = max(0, $activeProduct['current_stock']);
            $variations = is_array($activeProduct['variation']) ? $activeProduct['variation'] : json_decode($activeProduct['variation'], true);
            $firstVariation = collect($variations)->first(function ($variation) use ($detail) {
                return $variation['type'] == $detail['variant'];
            });

            if ($detail['variant'] && $firstVariation) {
                $currentStock = $firstVariation['qty'] ?? 0;
                $unitPrice = $firstVariation['price'] ?? 0;
            }

            if ($detail && $detail['is_stock_decreased'] == 1) {
                $currentStock += $details['qty'] ?? 1;
            }

            $detail['current_stock'] = $currentStock;
            $detail['current_price'] = $unitPrice;
            $detail['edit_order_payment_histories'] = $paymentInfo;
        }

        if ($firstDetails?->order) {
            $this->sanitizeShippingDecisionForSeller($firstDetails->order);
            $firstDetails->order->setAttribute('queue_state', app(OrderWorkflowService::class)->queueState($firstDetails->order));
        }

        return response()->json($detailsList, 200);
    }

    private function mustReturnRestrictedOrder(Order $order): bool
    {
        return in_array($order->commerce_flow_version, [config('order_commerce.new_flow_version'), \App\Services\PostPurchaseInvoiceService::CONTRACT_VERSION], true)
            && in_array($order->commerce_flow_status, [
                OrderCommerceState::SELLER_INSURANCE_PENDING,
                OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW,
            ], true);
    }

    private function sanitizeShippingDecisionForSeller(?Order $order): void
    {
        if (!$order) {
            return;
        }
        if (($order->shipping_assignment_status ?? 'pending') === 'assigned') {
            $decision = $order->shippingDecisions()->latest('id')->first();
            $responsible = $order->shipping_fulfillment_mode === 'seller_shipping' ? 'seller' : 'company';
            $transitions = OrderWorkflowService::TRANSITIONS[(string) $order->order_status] ?? [];
            if ($responsible === 'company') {
                $transitions = array_values(array_intersect($transitions, ['confirmed', 'processing', 'canceled']));
            }
            $order->setAttribute('shipping_assignment', [
                'status' => 'assigned', 'responsible_party' => $responsible,
                'mode' => $order->shipping_fulfillment_mode,
                'seller_cost' => (float) (($order->seller_shipping_allocation ?? 0) > 0
                    ? $order->seller_shipping_allocation
                    : ($order->shipping_seller_cost ?? 0)),
                'customer_paid' => (float) ($order->shipping_customer_cost ?? $order->shipping_cost ?? 0),
                'seller_entitlement' => (float) ($order->shipping_seller_entitlement ?? 0),
                'service_name' => $decision?->service_name ?: $order->delivery_service_name,
                'tracking_number' => $decision?->tracking_number ?: $order->third_party_delivery_tracking_id,
                'expected_delivery_date' => $decision?->expected_delivery_date?->format('Y-m-d')
                    ?: ($order->expected_delivery_date ? (string) $order->expected_delivery_date : null),
                'instructions' => $decision?->instructions,
                'sales_due_at' => $order->sales_settlement_due_at?->toIso8601String(),
                'shipping_due_at' => $order->shipping_settlement_due_at?->toIso8601String(),
                'shipping_due_separated' => (bool) $order->shipping_due_separated,
                'shipping_workflow_status' => $order->shipping_workflow_status ?: 'pending',
                'proof_upload_endpoint' => url("api/v3/seller/orders/{$order->id}/shipping-proofs"),
                'allowed_seller_statuses' => $transitions,
                'seller_can_manage_delivery' => $responsible === 'seller',
                'seller_shipping_response' => [
                    'status' => $order->seller_shipping_response_status,
                    'required' => in_array($order->seller_shipping_response_status, ['pending', 'overdue'], true),
                    'due_at' => $order->seller_shipping_response_due_at?->toIso8601String(),
                    'rejection_reason' => $order->seller_shipping_rejection_reason,
                    'decision_endpoint' => url("api/v3/seller/orders/{$order->id}/shipping-response"),
                ],
            ]);
            return;
        }

        // Until the admin saves a decision, do not expose a final method or charge.
        foreach ([
            'shipping_responsibility',
            'shipping_cost',
            'shipping_customer_cost',
            'shipping_seller_cost',
            'shipping_seller_entitlement',
            'delivery_service_name',
            'third_party_delivery_tracking_id',
            'expected_delivery_date',
        ] as $attribute) {
            $order->setAttribute($attribute, null);
        }
        $order->setAttribute('shipping_fulfillment_mode', 'pending');
    }

    private function resolveSellerOrderReference(int $sellerId, string $reference): ?Order
    {
        return Order::query()->where(['seller_id' => $sellerId, 'seller_is' => 'seller'])
            ->where(function ($query) use ($reference): void {
                if (ctype_digit($reference)) {
                    $query->whereKey((int) $reference);
                } else {
                    $query->where('seller_restricted_access_token', $reference);
                }
            })->first();
    }

    private function requiresShippingDecision(?Order $order): bool
    {
        if (!$order) {
            return false;
        }

        return $order->details->contains(function ($detail): bool {
            $snapshot = json_decode($detail->product_details ?? '', true);
            return ($detail->product?->product_type ?? $snapshot['product_type'] ?? 'physical') === 'physical';
        });
    }

    public function assign_delivery_man(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'delivery_man_id' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        $seller = $request->seller;
        $order = Order::with('deliveryMan')->where(['seller_id' => $seller['id'], 'id' => $request['order_id']])->first();
        if (!$order || $order->shipping_fulfillment_mode !== 'seller_shipping') {
            return response()->json(['success' => 0, 'message' => translate('shipping_status_is_managed_by_company')], 422);
        }

        if ($order['delivery_man_id'] != $request['delivery_man_id']) {
            $order->deliveryman_assigned_at = Carbon::now();
        }
        $order->delivery_man_id = $request['delivery_man_id'];
        $order->delivery_type = 'self_delivery';
        $order->delivery_service_name = null;
        $order->third_party_delivery_tracking_id = null;
        $order->save();
        app(OrderLogisticsService::class)->recordOrderStatus($order, $request['order_status'], 'seller', $seller->id);
        OrderStatusEvent::dispatch('new_order_assigned_message', 'delivery_man', $order);
        return response()->json(['success' => 1, 'message' => translate('order_deliveryman_assigned_successfully')], 200);
    }

    public function amount_date_update(Request $request): JsonResponse
    {
        $seller = $request->seller;

        $deliveryManCharge = $request->deliveryman_charge;

        $order = Order::with('deliveryMan')->find($request->order_id);
        $db_expected_date = $order->expected_delivery_date;

        $order->deliveryman_charge = $deliveryManCharge;
        $order->expected_delivery_date = $request->expected_delivery_date;

        try {
            DB::beginTransaction();

            if (!empty($request->expected_delivery_date) && $db_expected_date != $request->expected_delivery_date) {
                CommonTrait::add_expected_delivery_date_history($request->order_id, $seller['id'], $request->expected_delivery_date, 'seller');
            }
            $order->save();

            DB::commit();
        } catch (\Exception $ex) {
            DB::rollback();
            return response()->json(['success' => 0, 'message' => translate('Update fail!')], 403);
        }

        if (!empty($request->expected_delivery_date) && $db_expected_date != $request->expected_delivery_date) {
            OrderStatusEvent::dispatch('expected_delivery_date', 'delivery_man', $order);
        }

        return response()->json(['success' => 0, 'message' => translate('Updated successfully!')], 200);
    }

    /**
     *  Digital file upload after sell
     */
    public function digital_file_upload_after_sell(DigitalProductFileUploadAfterSell $request): JsonResponse
    {
        $seller = $request->seller;
        $order_details = OrderDetail::find($request->order_id);
        if ($order_details) {
            $order_details->digital_file_after_sell = ImageManager::update('product/digital-product/', $order_details->digital_file_after_sell, $request->digital_file_after_sell->getClientOriginalExtension(), $request->file('digital_file_after_sell'), 'file');
            $order_details->save();
            return response()->json(['success' => 1, 'message' => translate('File_upload_successfully')], 200);
        } else {
            return response()->json(['success' => 0, 'message' => translate("File_upload_fail!")], 202);
        }
    }

    public function order_detail_status(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $order = Order::with(['customer', 'seller.shop', 'deliveryMan', 'latestEditHistory'])->find($request['id']);
        if (!$order || (int) $order->seller_id !== (int) $seller->id || $order->seller_is !== 'seller') {
            return response()->json(['status' => 0, 'message' => translate('Order_not_found')], 404);
        }
        if (in_array($order->commerce_flow_version, [config('order_commerce.new_flow_version'), \App\Services\PostPurchaseInvoiceService::CONTRACT_VERSION], true)) {
            $insurance = $order->sellerOrderInsurance;
            if ($insurance && !$this->sellerOrderInsuranceService->canViewDetails($insurance)) {
                return response()->json(['status' => 0, 'message' => translate('seller_order_insurance_payment_required_before_order_details')], 423);
            }
        }
        if ($order->shipping_fulfillment_mode === 'admin_shipping'
            && in_array($request['order_status'], ['out_for_delivery', 'delivered', 'returned', 'failed'], true)) {
            return response()->json(['status' => 0, 'message' => translate('shipping_status_is_managed_by_company')], 422);
        }
        if ($this->requiresShippingDecision($order) && ($order->shipping_assignment_status ?? 'pending') !== 'assigned') {
            return response()->json([
                'status' => 0,
                'message' => translate('admin_shipping_decision_required_before_processing_order'),
            ], 202);
        }
        if (in_array($order->commerce_flow_version, [config('order_commerce.new_flow_version'), \App\Services\PostPurchaseInvoiceService::CONTRACT_VERSION], true)
            && in_array($order->seller_shipping_response_status, ['pending', 'overdue'], true)
            && $request['order_status'] !== 'canceled') {
            return response()->json(['status' => 0, 'message' => translate('accept_or_reject_shipping_terms_before_processing')], 422);
        }
        if (!$order->is_guest && empty($order->customer)) {
            return response()->json(['success' => 0, 'message' => translate("Customer_account_has_been_deleted") . ' ' . translate("you_cant_update_status")], 202);
        }

        if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid') {
            return response()->json(['status' => 0, 'message' => translate('Please confirm the offline payment information before changing the order status.')], 202);
        }

        if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid') {
            return response()->json(['status' => 0, 'message' => translate('After admin confirm the due amount payment as paid then you can change this status.')], 202);
        }

        if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_return_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_return_payment_status == 'pending') {
            return response()->json(['status' => 0, 'message' => translate('After admin confirm the return amount payment as returned then you can change this status.')], 202);
        }

        if ($order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order['shipping_responsibility'] == 'inhouse_shipping' && $request['order_status'] == 'delivered') {
            return response()->json(['status' => 0, 'message' => translate('Please mark as paid before delivered this order.')], 202);
        }

        $sellerHandlesShipping = $order->shipping_fulfillment_mode === 'seller_shipping'
            || $order->shipping_responsibility === 'sellerwise_shipping';
        if ($request['order_status'] === 'delivered' && $sellerHandlesShipping
            && (!Schema::hasTable('order_shipping_proofs')
                || !$order->shippingProofs()->where('shipping_status', 'delivered')->exists())) {
            return response()->json(['status' => 0, 'message' => 'يجب رفع إثبات التسليم لإتمام الطلب.'], 422);
        }

        $walletStatus = getWebConfig(name: 'wallet_status');
        $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');

        if ($order->order_status == 'delivered') {
            return response()->json(['success' => 0, 'message' => translate('order is already delivered')], 200);
        }

        try {
            app(OrderWorkflowService::class)->assertTransition((string) $order->order_status, (string) $request['order_status'], 'seller');
        } catch (DomainException) {
            return response()->json(['success' => 0, 'message' => translate('order_status_transition_not_allowed')], 422);
        }
        $previousStatus = (string) $order->order_status;
        event(new OrderStatusEvent(key: $request['order_status'], type: 'customer', order: $order));
        if ($request->order_status == 'canceled') {
            event(new OrderStatusEvent(key: 'canceled', type: 'delivery_man', order: $order));
        }

        $order->order_status = $request['order_status'];
        if ($request['order_status'] == 'delivered') {
            $order->payment_status = 'paid';
            Order::where('id', $order->id)->update(['is_pause' => 0]);
            OrderDetail::where('order_id', $order->id)->update(['delivery_status' => 'delivered', 'payment_status' => 'paid']);
            OrderDetail::where('order_id', $order['id'])->whereNull('refund_started_at')->update(['refund_started_at' => now()]);
        }
        OrderManager::getStockUpdateOnOrderStatusChange($order, $request->order_status);
        if ($request->order_status == 'delivered' && $order['seller_id'] != null) {
            OrderManager::getWalletManageOnOrderStatusChange($order, 'seller');
        }

        $order->save();
        app(OrderLogisticsService::class)->recordOrderStatus($order, $request['order_status'], 'seller', $seller->id, $previousStatus);

        if ($order->delivery_man_id && $request->order_status == 'delivered') {
            $deliverymanWallet = DeliverymanWallet::where('delivery_man_id', $order->delivery_man_id)->first();
            $cashInHand = $order->payment_method == 'cash_on_delivery' ? $order->order_amount : 0;

            if (empty($deliverymanWallet)) {
                DeliverymanWallet::create([
                    'delivery_man_id' => $order->delivery_man_id,
                    'current_balance' => $order?->deliveryman_charge ?? 0,
                    'cash_in_hand' => $cashInHand,
                    'pending_withdraw' => 0,
                    'total_withdraw' => 0,
                ]);
            } else {
                $deliverymanWallet->current_balance += $order?->deliveryman_charge ?? 0;
                $deliverymanWallet->cash_in_hand += $cashInHand;
                $deliverymanWallet->save();
            }

            if ($order->deliveryman_charge && $request->order_status == 'delivered') {
                DeliveryManTransaction::create([
                    'delivery_man_id' => $order->delivery_man_id,
                    'user_id' => $seller->id,
                    'user_type' => 'seller',
                    'credit' => $order?->deliveryman_charge ?? 0,
                    'transaction_id' => Uuid::uuid4(),
                    'transaction_type' => 'deliveryman_charge'
                ]);
            }
        }

        if (!$order->is_guest && $walletStatus == 1 && $loyaltyPointStatus == 1) {
            if ($request->order_status == 'delivered') {
                CustomerManager::create_loyalty_point_transaction($order->customer_id, $order->id, Convert::default($order->order_amount - $order->shipping_cost), 'order_place');
            }
        }

        $refEarningStatus = BusinessSetting::where('type', 'ref_earning_status')->first()->value ?? 0;
        $refEarningExchangeRate = BusinessSetting::where('type', 'ref_earning_exchange_rate')->first()->value ?? 0;

        if (!$order->is_guest && $walletStatus == 1 && $refEarningStatus == 1 && $request->order_status == 'delivered') {

            $customer = User::find($order->customer_id);
            $isFirstOrder = Order::where(['customer_id' => $order->customer_id, 'order_status' => 'delivered', 'payment_status' => 'paid'])->count();
            $referredByUser = User::find($customer->referred_by);

            if ($isFirstOrder == 1 && isset($customer->referred_by) && isset($referredByUser)) {
                CustomerManager::create_wallet_transaction($referredByUser->id, floatval($refEarningExchangeRate), 'add_fund_by_admin', 'earned_by_referral');
            }
        }

        OrderManager::generateReferBonusForFirstOrder(orderId: $order['id']);
        if ($request['order_status'] == 'delivered') {
            $referredUser = ReferralCustomer::where('user_id', $order?->customer?->id)->first();
            if ($referredUser?->delivered_notify != 1) {
                event(new OrderStatusEvent(key: 'your_referred_customer_order_has_been_delivered', type: 'promoter', order: $order));
                ReferralCustomer::where('user_id', $order?->customer?->id)->update(['delivered_notify' => 1]);
            }
        }
        self::add_order_status_history($order->id, $seller->id, $request->order_status, 'seller');

        return response()->json(['success' => 1, 'message' => translate('order_status_updated_successfully')], 200);
    }

    public function assign_third_party_delivery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'delivery_service_name' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        $order = Order::find($request->order_id);
        $order->delivery_type = 'third_party_delivery';
        $order->delivery_service_name = $request->delivery_service_name;
        $order->third_party_delivery_tracking_id = $request->third_party_delivery_tracking_id;
        $order->delivery_man_id = null;
        $order->deliveryman_charge = 0;
        $order->expected_delivery_date = null;
        $order->save();

        return response()->json(['success' => 1, 'message' => translate('third_party_delivery_assigned_successfully')], 200);
    }

    public function update_payment_status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_status' => 'required|in:paid,unpaid'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }
        if ($request->payment_status != 'paid') {
            return response()->json(['success' => 0, 'message' => translate('When payment status paid then you can`t change payment status paid to unpaid') . '.'], 200);
        }
        $order = Order::find($request['order_id']);
        if (isset($order)) {
            if ($order->is_guest == '0' && empty($order->customer)) {
                return response()->json(['success' => 0, 'message' => translate("Customer account has been deleted. you can't update status!")], 202);
            }

            if ($order['payment_method'] == 'cash_on_delivery' && $order['order_status'] != 'delivered' && $request['payment_status'] == 'paid') {
                return response()->json([
                    'errors' => [
                        ['code' => 'order', 'message' => translate('Can not change payment status before order delivered!')]
                    ]
                ], 404);
            }

            $order->payment_status = $request['payment_status'];
            $order->save();
            return response()->json(['message' => translate('Payment status updated')], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('not found!')]
            ]
        ], 404);
    }

    public function address_update(Request $request)
    {
        $order = $this->order->find($request->order_id)->toArray();
        $shipping_address_data = $order['shipping_address_data'] ? json_decode(json_encode($order['shipping_address_data']), true) : [];
        $billing_address_data = $order['billing_address_data'] ? json_decode(json_encode($order['billing_address_data']), true) : [];

        $common_address_data = [
            'contact_person_name' => $request->contact_person_name,
            'phone' => $request->phone,
            'city' => $request->city,
            'zip' => $request->zip,
            'email' => $request->email,
            'address' => $request->address,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'updated_at' => now(),
        ];

        if ($request->address_type == 'shipping') {
            $shipping_address_data = array_merge($shipping_address_data, $common_address_data);
        } elseif ($request->address_type == 'billing') {
            $billing_address_data = array_merge($billing_address_data, $common_address_data);
        }
        $update_data = [];

        if ($request->address_type == 'shipping') {
            $update_data['shipping_address_data'] = json_encode($shipping_address_data);
        } elseif ($request->address_type == 'billing') {
            $update_data['billing_address_data'] = json_encode($billing_address_data);
        }

        if (!empty($update_data)) {
            DB::table('orders')->where('id', $request->order_id)->update($update_data);
        }

        return response()->json(['message' => 'Address updated successfully'], 200);
    }

    public function updateOrderDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_status' => 'required|in:paid,unpaid',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $seller = $request->seller;
        $order = Order::with(['customer', 'seller.shop', 'deliveryMan', 'latestEditHistory'])->find($request['order_id']);

        if (isset($order)) {
            if ($order['payment_status'] == 'paid' && $request['payment_status'] != 'paid') {
                return response()->json(['success' => 0, 'message' => translate('when_payment_status_paid_then_you_can_not_change_payment_status_paid_to_unpaid.')], 403);
            }

            if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid') {
                return response()->json(['status' => 0, 'message' => translate('Please confirm the offline payment information before changing the order status.')], 403);
            }

            if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid') {
                return response()->json(['status' => 0, 'message' => translate('After admin confirm the due amount payment as paid then you can change this status.')], 403);
            }

            if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_return_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_return_payment_status == 'pending') {
                return response()->json(['status' => 0, 'message' => translate('After admin confirm the return amount payment as returned then you can change this status.')], 403);
            }

            if ($order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order['shipping_responsibility'] == 'inhouse_shipping' && $request['order_status'] == 'delivered') {
                return response()->json([
                    'status' => 0,
                    'message' => translate('Please mark as paid before delivered this order.'),
                ], 403);
            }

            if ($request['order_status'] == 'delivered') {
                foreach ($order['details'] as $orderDetail) {
                    $productDetails = json_decode($orderDetail?->product_details ?? '', true);
                    if (
                        $productDetails['product_type'] == 'digital' &&
                        (isset($productDetails['digital_product_type']) && $productDetails['digital_product_type'] == 'ready_after_sell') &&
                        is_null($orderDetail['digital_file_after_sell'])
                    ) {
                        return response()->json(['success' => 0, 'message' => translate('Please_upload_the_digital_product_files_first')], 403);
                    }
                }
            }

            if ($request['delivery_type'] == 'third_party_delivery') {
                Order::where('id', $request['order_id'])->update([
                    'delivery_man_id' => null,
                    'deliveryman_charge' => 0,
                    'expected_delivery_date' => null,
                    'delivery_type' => 'third_party_delivery',
                    'delivery_service_name' => $request['delivery_service_name'] ?? '',
                    'third_party_delivery_tracking_id' => $request['third_party_delivery_tracking_id'] ?? '',
                ]);
            } elseif ($request->has('delivery_man_id') && !empty($request['delivery_man_id']) && ($order['delivery_man_id'] != $request['delivery_man_id'])) {
                Order::where('id', $request['order_id'])->update([
                    'delivery_man_id' => $request['delivery_man_id'],
                    'delivery_type' => 'self_delivery',
                    'delivery_service_name' => null,
                    'third_party_delivery_tracking_id' => null,
                ]);
                OrderStatusEvent::dispatch('new_order_assigned_message', 'delivery_man', $order);
            }

            if ($request->has('deliveryman_charge') && !is_null($request['deliveryman_charge']) && ($order['deliveryman_charge'] != $request['deliveryman_charge'])) {
                Order::where(['id' => $request['order_id']])->update([
                    'deliveryman_charge' => $request['deliveryman_charge'],
                ]);
            }

            $orderInfo = Order::with('deliveryMan')->find($request['order_id']);
            if (!empty($request['expected_delivery_date']) && $orderInfo['expected_delivery_date'] != $request['expected_delivery_date']) {
                $orderInfo->expected_delivery_date = $request['expected_delivery_date'];
                try {
                    DB::beginTransaction();
                    $this->add_expected_delivery_date_history($request['order_id'], $seller['id'], $request['expected_delivery_date'], 'seller');
                    $orderInfo->save();
                    DB::commit();
                } catch (\Exception $ex) {
                    DB::rollback();
                }

                OrderStatusEvent::dispatch('expected_delivery_date', 'delivery_man', $order);
            }

            // Order Status
            if ($request->has('order_status') && !empty($request['order_status'])) {
                $order = Order::with(['customer', 'seller.shop', 'deliveryMan'])->find($request['order_id']);

                if ($order['is_guest'] == 0 && empty($order->customer)) {
                    return response()->json([
                        'success' => 0,
                        'message' => translate("Customer_account_has_been_deleted") . ' ' . translate("you_cant_update_status")
                    ], 202);
                }

                $walletStatus = getWebConfig(name: 'wallet_status');
                $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');

                if ($order['order_status'] == 'delivered' && !in_array($request['order_status'], ['returned', 'failed', 'canceled'])) {
                    return response()->json(['success' => 0, 'message' => translate('order_is_already_delivered')], 200);
                }

                try {
                    app(OrderWorkflowService::class)->assertTransition((string) $order->order_status, (string) $request['order_status'], 'seller');
                } catch (DomainException) {
                    return response()->json(['success' => 0, 'message' => translate('order_status_transition_not_allowed')], 422);
                }
                $previousStatus = (string) $order->order_status;
                event(new OrderStatusEvent(key: $request['order_status'], type: 'customer', order: $order));
                if ($request['order_status'] == 'canceled') {
                    event(new OrderStatusEvent(key: 'canceled', type: 'delivery_man', order: $order));
                }

                Order::where('id', $request['order_id'])->update(['order_status' => $request['order_status']]);
                if ($request['order_status'] == 'delivered') {
                    Order::where('id', $request['order_id'])->update([
                        'payment_status' => 'paid',
                        'is_pause' => 0,
                    ]);
                    OrderDetail::where('order_id', $order->id)->update(['delivery_status' => 'delivered', 'payment_status' => 'paid']);
                }
                app(OrderLogisticsService::class)->recordOrderStatus(
                    Order::query()->findOrFail($request['order_id']),
                    $request['order_status'],
                    'seller',
                    $seller->id,
                    $previousStatus,
                );
                OrderManager::getStockUpdateOnOrderStatusChange($order, $request['order_status']);
                if ($request['order_status'] == 'delivered' && $order['seller_id'] != null) {
                    OrderManager::getWalletManageOnOrderStatusChange($order, 'seller');

                }

                if ($order['delivery_man_id'] && $request['order_status'] == 'delivered') {
                    $deliverymanWallet = DeliverymanWallet::where('delivery_man_id', $order['delivery_man_id'])->first();
                    $cashInHand = $order['payment_method'] == 'cash_on_delivery' ? $order['order_amount'] : 0;

                    if (empty($deliverymanWallet)) {
                        DeliverymanWallet::create([
                            'delivery_man_id' => $order['delivery_man_id'],
                            'current_balance' => $order?->deliveryman_charge ?? 0,
                            'cash_in_hand' => $cashInHand,
                            'pending_withdraw' => 0,
                            'total_withdraw' => 0,
                        ]);
                    } else {
                        DeliverymanWallet::where('delivery_man_id', $order['delivery_man_id'])->update([
                            'current_balance' => $order?->deliveryman_charge ?? 0,
                            'cash_in_hand' => $cashInHand,
                        ]);
                    }

                    if ($order['deliveryman_charge'] && $request['order_status'] == 'delivered') {
                        DeliveryManTransaction::create([
                            'delivery_man_id' => $order['delivery_man_id'],
                            'user_id' => $seller->id,
                            'user_type' => 'seller',
                            'credit' => $order?->deliveryman_charge ?? 0,
                            'transaction_id' => Uuid::uuid4(),
                            'transaction_type' => 'deliveryman_charge'
                        ]);
                    }
                }

                if (!$order['is_guest'] && $walletStatus == 1 && $loyaltyPointStatus == 1) {
                    if ($request['order_status'] == 'delivered') {
                        CustomerManager::create_loyalty_point_transaction($order['customer_id'], $order['id'], Convert::default($order['order_amount'] - $order['shipping_cost']), 'order_place');
                    }
                }

                $refEarningStatus = BusinessSetting::where('type', 'ref_earning_status')->first()->value ?? 0;
                $refEarningExchangeRate = BusinessSetting::where('type', 'ref_earning_exchange_rate')->first()->value ?? 0;

                if (!$order['is_guest'] && $walletStatus == 1 && $refEarningStatus == 1 && $request['order_status'] == 'delivered') {
                    $customer = User::find($order['customer_id']);
                    $isFirstOrder = Order::where(['customer_id' => $order['customer_id'], 'order_status' => 'delivered', 'payment_status' => 'paid'])->count();
                    $referredByUser = User::find($customer->referred_by);

                    if ($isFirstOrder == 1 && isset($customer->referred_by) && isset($referredByUser)) {
                        CustomerManager::create_wallet_transaction($referredByUser->id, floatval($refEarningExchangeRate), 'add_fund_by_admin', 'earned_by_referral');
                    }
                }

                self::add_order_status_history($order['id'], $seller->id, $request['order_status'], 'seller');
            }

            $order = Order::with(['customer', 'seller.shop', 'deliveryMan'])->find($request['order_id']);
            if ($order['payment_status'] != 'paid' && $request['payment_status'] == 'paid') {
                if ($order['is_guest'] == '0' && empty($order?->customer)) {
                    return response()->json([
                        'success' => 0,
                        'message' => translate("customer_account_has_been_deleted.") . ' ' . translate('you_can_not_update_status.'),
                    ], 200);
                }
                Order::where('id', $request['order_id'])->update(['payment_status' => $request['payment_status']]);
            }

            return response()->json([
                'success' => 1,
                'message' => translate("Order_updated_successfully")
            ], 200);
        }

        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('not found!')]
            ]
        ], 404);
    }
}
