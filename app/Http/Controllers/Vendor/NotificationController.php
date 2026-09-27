<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Enums\ViewPaths\Vendor\Notification;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Vendor\NotificationModalViewRequest;
use App\Repositories\NotificationSeenRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Order;
use App\Support\Commerce\OrderCommerceState;

class NotificationController extends BaseController
{
    /**
     * @param ShopRepositoryInterface $shopRepo
     * @param NotificationRepositoryInterface $notificationRepo
     * @param NotificationSeenRepository $notificationSeenRepo
     */
    public function __construct(
        private readonly ShopRepositoryInterface $shopRepo,
        private readonly NotificationRepositoryInterface $notificationRepo,
        private readonly NotificationSeenRepository $notificationSeenRepo,
    )
    {
    }


    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     */
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        return null;
    }

    /**
     * @param NotificationModalViewRequest $request
     * @return JsonResponse
     */
    public function getNotificationModalView(NotificationModalViewRequest $request ):JsonResponse
    {
        $shop = $this->shopRepo->getFirstWhere(params:['seller_id'=> auth('seller')->id()]);
        $companyName = getWebConfig(name: 'company_name') ?? '';

        $notificationSeenId = $this->notificationSeenRepo->getFirstWhere(params:['seller_id' => auth('seller')->id(), 'notification_id' => $request['id']]);

        $this->notificationSeenRepo->update(id: $notificationSeenId['id'],data: [ 'created_at' => now()]);

        $data = $this->notificationRepo->getFirstWhere(params:['id' => $request['id']]);

        $actionUrl = null;
        $actionLabel = null;
        if ($data && preg_match('/#(\d+)/', (string) $data->description, $matches)) {
            $order = Order::query()
                ->whereKey((int) $matches[1])
                ->where(['seller_id' => auth('seller')->id(), 'seller_is' => 'seller'])
                ->where('admin_order_review_status', 'approved')
                ->whereIn('commerce_flow_status', [
                    OrderCommerceState::SELLER_INSURANCE_PENDING,
                    OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW,
                    OrderCommerceState::RELEASED_TO_SELLER,
                    OrderCommerceState::FULFILLMENT_IN_PROGRESS,
                ])->first();
            if ($order) {
                $restricted = in_array($order->commerce_flow_status, [OrderCommerceState::SELLER_INSURANCE_PENDING, OrderCommerceState::SELLER_INSURANCE_UNDER_REVIEW], true);
                $actionUrl = route('vendor.orders.details', $restricted ? ($order->seller_restricted_access_token ?: $order->id) : $order->id);
                $actionLabel = $restricted ? translate('continue_to_pay_insurance') : translate('view_order');
            }
        }

        $notification = $this->notificationRepo->getListWhereBetween(params:[auth('seller')->user()->created_at, now()],filters: ['sent_to'=>'seller'],relations: 'notificationSeenBy');

        $notificationCount = count($notification);
        return response()->json([
            'notification_count' => $notificationCount,
            'view' => view(Notification::INDEX[VIEW], compact('shop', 'companyName', 'data', 'actionUrl', 'actionLabel'))->render(),
        ]);
    }
}
