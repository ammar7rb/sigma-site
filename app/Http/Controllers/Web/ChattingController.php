<?php

namespace App\Http\Controllers\Web;

use App\Contracts\Repositories\ChattingRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DeliveryManRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ViewPaths\Web\Chatting;
use App\Events\ChattingEvent;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Web\ChattingRequest;
use App\Services\ChattingService;
use App\Traits\PushNotificationTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

class ChattingController extends BaseController
{
    use PushNotificationTrait;

    /**
     * @param ChattingRepositoryInterface $chattingRepo
     * @param ShopRepositoryInterface $shopRepo
     * @param ChattingService $chattingService
     * @param DeliveryManRepositoryInterface $deliveryManRepo
     * @param CustomerRepositoryInterface $customerRepo
     * @param VendorRepositoryInterface $vendorRepo
     */
    public function __construct(
        private readonly ChattingRepositoryInterface    $chattingRepo,
        private readonly ShopRepositoryInterface        $shopRepo,
        private readonly ChattingService                $chattingService,
        private readonly DeliveryManRepositoryInterface $deliveryManRepo,
        private readonly CustomerRepositoryInterface    $customerRepo,
        private readonly VendorRepositoryInterface      $vendorRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|array|null $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     */
    public function index(?Request $request, string|array|null $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        Toastr::info(translate('please_open_a_support_ticket_to_contact_the_platform'));
        return redirect()->route('account-tickets');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function getMessageByUser(Request $request): JsonResponse
    {
        return $this->directChatBlockedResponse();
    }

    /**
     * @param ChattingRequest $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function addMessage(ChattingRequest $request): JsonResponse
    {
        return $this->directChatBlockedResponse();

        if($request->hasFile('file')) {
            foreach ($request->file('file') as $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                if (in_array($extension, getDisallowedExtensionsListArray())) {
                    if (env('APP_MODE', 'dev') == 'demo') {
                        return response()->json([
                            'status' => 'error',
                            'message' => translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode')
                        ]);
                    }

                    return response()->json([
                        'status' => 'error',
                        'message' => translate('Files_with_extensions_like') .
                            ' (' . implode(', ', array_map(fn($ext) => '.' . $ext, getDisallowedExtensionsListArray())) . ') ' .
                            translate('are_not_supported') . '!'
                    ]);
                }
            }
        }
        $customerId = auth('customer')->id();
        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $customerId]);
        if ($request->has(key: 'delivery_man_id')) {
            $this->chattingRepo->add(
                data: $this->chattingService->addChattingDataForWeb(
                    request: $request,
                    userId: $customerId,
                    type: 'deliveryman',
                    deliveryManId: $request['delivery_man_id']
                )
            );
            $getUser = $this->deliveryManRepo->getFirstWhere(params: ['id' => $request['delivery_man_id']]);
            $requestColumn = 'delivery_man_id';
            $requestId = $request['delivery_man_id'];
            $whereNotNull = ['user_id', 'delivery_man_id'];
            $relation = ['deliveryMan'];
            $type = 'delivery-man';
            event(new ChattingEvent(key: 'message_from_customer', type: 'delivery_man', userData: $getUser, messageForm: $customer));
        } else {
            return $this->directChatBlockedResponse();
        }
        $chattingMessages = $this->getMessage(requestColumn: $requestColumn, requestId: $requestId, whereNotNull: $whereNotNull, relation: $relation);
        $data = self::getRenderMessagesView(user: $getUser, message: $chattingMessages, type: $type);
        return response()->json($data);
    }

    /**
     * @param array $relation
     * @param string $columnName
     * @param string $type
     * @return View
     */
    private function getChatList(array $relation, string $columnName, string $type): View
    {
        $customerId = auth('customer')->id();
        $allChattingUsers = $this->chattingRepo->getListWhereNotNull(
            orderBy: ['id' => 'DESC'],
            filters: ['user_id' => $customerId],
            whereNotNull: [$columnName],
            relations: $relation,
            dataLimit: 'all'
        )->unique($columnName);

        if ($type == 'vendor') {
            $inHouseInfo = $this->chattingRepo->getListWhereNotNull(
                orderBy: ['id' => 'DESC'],
                filters: ['user_id' => $customerId],
                whereNotNull: ['admin_id'],
                relations: ['admin'],
                dataLimit: 'all'
            )->unique('admin_id');
            $allChattingUsers = $inHouseInfo->count() > 0 ? ($allChattingUsers->merge($inHouseInfo))->sortByDesc('id')->values() : $allChattingUsers;
        }
        $allChattingUsers?->map(function ($chatting, $index) use ($allChattingUsers, $customerId) {
            $filterColumn = !is_null($chatting?->admin_id) ? 'admin_id' : (!is_null($chatting?->seller_id) ? 'seller_id' : 'delivery_man_id');
            $filterId = $chatting?->admin_id ?? ($chatting?->seller_id ? $chatting->shop->id : $chatting->deliveryMan->id);
            $filter = [
                'user_id' => $customerId,
                $filterColumn => $filterId,
                'sent_by_customer' => 0,
                'seen_by_customer' => 0,
            ];
            $unseenMessageCount = $this->chattingRepo->getListWhere(
                filters: $filter, dataLimit: 'all'
            )->count();
            if ($index === 0) {
                $chatting['unseen_message_count'] = 0;
            } else {
                $chatting['unseen_message_count'] = $unseenMessageCount;
            }
        });
        $lastChatUser = null;
        foreach ($allChattingUsers as $key => $value) {
            $lastChatUser = (!is_null($value->admin_id) ? ['id' => 0] : (!is_null($value->seller_id) ? $value->shop : $value->deliveryMan));
            if (!is_null($value->admin_id)) {
                $columnName = 'admin_id';
                $type = 'admin';
                $relation = ['admin'];
            }
            break;
        }
        if ($lastChatUser) {
            $this->updateAllUnseenMessageStatus(requestColumn: $columnName, requestId: $lastChatUser['id']);
            $chattingMessages = $this->getMessage(requestColumn: $columnName, requestId: $lastChatUser['id'], whereNotNull: ['user_id', $columnName], relation: $relation);
        } else {
            $chattingMessages = [];
        }
        return view(VIEW_FILE_NAMES['user_inbox'], [
            'userType' => $type,
            'userData' => $lastChatUser ? $this->getUserData(type: $type, user: ($lastChatUser['id'] == 0 ? 'admin' : $lastChatUser)) : '',
            'allChattingUsers' => $allChattingUsers,
            'lastChatUser' => $lastChatUser,
            'chattingMessages' => $chattingMessages,
        ]);
    }

    /**
     * @param object|string $user
     * @param object $message
     * @param string $type
     * @return array
     * @throws Throwable
     */
    protected function getRenderMessagesView(object|string $user, object $message, string $type): array
    {
        return [
            'userData' => $this->getUserData(type: $type, user: $user),
            'chattingMessages' => view(VIEW_FILE_NAMES['user_inbox_message'], [
                'lastChatUser' => $user,
                'userType' => $type,
                'chattingMessages' => $message
            ])->render(),
        ];
    }

    private function getUserData($type, $user): array
    {
        if ($type == 'vendor') {
            $userData = ['name' => $user['name'], 'phone' => $user['contact']];
            $userData['image'] = getStorageImages(path: $user->image_full_url, type: 'shop');
            $userData['temporary-close-status'] = (int)checkVendorAbility(type: 'vendor', status: 'temporary_close', vendor: $user);
        } elseif ($type == 'delivery-man') {
            $userData = ['name' => $user['f_name'] . ' ' . $user['l_name'], 'phone' => $user['country_code'] . $user['phone']];
            $userData['image'] = getStorageImages(path: $user->image_full_url, type: 'avatar');
            $userData['temporary-close-status'] = '';
        } else {
            $userData = ['name' => getInHouseShopConfig(key: 'name'), 'phone' => ''];
            $userData['image'] = getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop');
            $userData['temporary-close-status'] = (int)checkVendorAbility(type: 'inhouse', status: 'temporary_close');
        }
        return $userData;
    }

    private function getMessage($requestColumn, $requestId, $whereNotNull, $relation): Collection
    {
        $customerId = auth('customer')->id();
        $orderBy = theme_root_path() == 'default' ? ['id' => 'DESC'] : ['id' => 'ASC'];
        return $this->chattingRepo->getListWhereNotNull(
            orderBy: $orderBy,
            filters: ['user_id' => $customerId, $requestColumn => $requestId],
            whereNotNull: $whereNotNull,
            relations: $relation,
            dataLimit: 'all'
        );
    }

    private function updateAllUnseenMessageStatus($requestColumn, $requestId): void
    {
        $customerId = auth('customer')->id();
        $this->chattingRepo->updateAllWhere(
            params: ['user_id' => $customerId, $requestColumn => $requestId],
            data: ['seen_by_customer' => 1]
        );
    }

    private function directChatBlockedResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => translate('please_open_a_support_ticket_to_contact_the_platform'),
        ]);
    }
}
