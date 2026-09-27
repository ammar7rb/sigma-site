<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Requests\Admin\CustomerProfileUpdateRequest;
use Carbon\Carbon;
use App\Enums\WebConfigKey;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Traits\PaginatorTrait;
use App\Traits\PushNotificationTrait;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use App\Traits\EmailTemplateTrait;
use App\Exports\CustomerListExport;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SubscriberListExport;
use Illuminate\Http\RedirectResponse;
use App\Services\PasswordResetService;
use App\Services\ReferByEarnCustomerService;
use App\Exports\CustomerOrderListExport;
use App\Http\Controllers\BaseController;
use App\Models\CustomerPurchaseLimitTransaction;
use App\Models\CustomerPurchasePackageSubscription;
use App\Models\OfflinePaymentMethod;
use App\Models\BusinessCalendarHoliday;
use App\Services\CustomerPurchaseLimitService;
use App\Services\CustomerThreeStepShippingService;
use App\Services\ShippingAddressService;
use App\Events\CustomerRegistrationEvent;
use App\Events\CustomerStatusUpdateEvent;
use App\Http\Requests\Admin\CustomerRequest;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use App\Repositories\ShippingAddressRepository;
use App\Contracts\Repositories\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Http\Requests\Admin\CustomerUpdateSettingsRequest;
use App\Contracts\Repositories\CurrencyRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Contracts\Repositories\SubscriptionRepositoryInterface;
use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Contracts\Repositories\RefundRequestRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Models\AccountActivationCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

class CustomerController extends BaseController
{
    use PaginatorTrait, EmailTemplateTrait, PushNotificationTrait;

    public function __construct(
        private readonly CustomerRepositoryInterface        $customerRepo,
        private readonly TranslationRepositoryInterface     $translationRepo,
        private readonly OrderRepositoryInterface           $orderRepo,
        private readonly SubscriptionRepositoryInterface    $subscriptionRepo,
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly RefundRequestRepositoryInterface   $refundRequestRepo,
        private readonly PasswordResetRepositoryInterface   $passwordResetRepo,
        private readonly PasswordResetService               $passwordResetService,
        private readonly ShippingAddressRepository          $shippingAddressRepo,
        private readonly ShippingAddressService             $shippingAddressService,
        private readonly CurrencyRepositoryInterface        $currencyRepo,
        private readonly ReferByEarnCustomerService         $referByEarnCustomerService,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|RedirectResponse|JsonResponse Index function is the starting point of a controller
     * Index function is the starting point of a controller
     * @throws Exception
     */
    public function index(Request|null $request, ?string $type = null): View|RedirectResponse|JsonResponse
    {
        $filters = [
            'is_active' => $request['is_active'] ?? null,
            'order_date' => $request['order_date'],
            'sort_by' => $request['sort_by'] ?? null,
            'avoid_walking_customer' => 1,
        ];
        $takeItem = $request->get('choose_first');

        if (isset($request['order_date']) && !empty($request['order_date'])) {
            $dates = explode(' - ', $request['order_date']);
            if (count($dates) !== 2 || !checkDateFormatInMDY($dates[0]) || !checkDateFormatInMDY($dates[1])) {
                ToastMagic::error(translate('Invalid_date_range_format'));
                return back();
            }
        }

        $joiningStartDate = '';
        $joiningEndDate = '';
        if (isset($request['customer_joining_date']) && !empty($request['customer_joining_date'])) {
            $dates = explode(' - ', $request['customer_joining_date']);
            if (count($dates) !== 2 || !checkDateFormatInMDY($dates[0]) || !checkDateFormatInMDY($dates[1])) {
                ToastMagic::error(translate('Invalid_date_range_format'));
                return back();
            }
            $joiningStartDate = Carbon::createFromFormat('m/d/Y', $dates[0])->startOfDay();
            $joiningEndDate = Carbon::createFromFormat('m/d/Y', $dates[1])->endOfDay();
        }

        $customers = $this->customerRepo->getListWhereBetween(
            searchValue: $request['searchValue'],
            filters: $filters,
            relations: ['orders'],
            whereBetween: 'created_at',
            whereBetweenFilters: $joiningStartDate && $joiningEndDate ? [$joiningStartDate, $joiningEndDate] : [],
            takeItem: $takeItem,
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT),
            appends: $request->all(),
        );
        $totalCustomers = $this->customerRepo->getListWhereBetween(filters: ['avoid_walking_customer' => 1], dataLimit: 'all')->count();
        $customerActivePackages = CustomerPurchasePackageSubscription::with('package')
            ->whereIn('customer_id', $customers->getCollection()->pluck('id')->toArray())
            ->where('status', 'active')
            ->where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', Carbon::now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', Carbon::now());
            })
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($subscriptions) => $subscriptions->first());

        return view('admin-views.customer.list', [
            'customers' => $customers,
            'totalCustomers' => $totalCustomers,
            'customerActivePackages' => $customerActivePackages,
        ]);
    }


    public function updateStatus(Request $request): JsonResponse
    {
        $this->customerRepo->update(id: $request['id'], data: ['is_active' => $request->get('is_active', 0)]);
        $this->customerRepo->deleteAuthAccessTokens(id: $request['id']);
        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $request['id']]);
        $data = [
            'userName' => $customer['f_name'],
            'userType' => 'customer',
            'templateName' => $customer['is_active'] ? 'account-unblock' : 'account-block',
            'subject' => $customer['is_active'] ? translate('Account_Unblocked') . ' !' : translate('Account_Blocked') . ' !',
            'title' => $customer['is_active'] ? translate('Account_Unblocked') . ' !' : translate('Account_Blocked') . ' !',
        ];
        event(new CustomerStatusUpdateEvent(email: $customer['email'], data: $data));
        return response()->json(['message' => translate('update_successfully')]);
    }

    public function getView(Request $request, $id): View|RedirectResponse
    {
        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $id], relations: ['addresses']);
        if (isset($customer)) {
            $orders = $this->orderRepo->getListWhere(orderBy: ['id' => 'desc'], searchValue: $request['searchValue'], filters: ['customer_id' => $id, 'is_guest' => '0'], dataLimit: 'all');
            $orderStatusArray = [
                'total_order' => 0,
                'ongoing' => 0,
                'completed' => 0,
                'returned' => 0,
                'refunded' => count($customer->refundOrders),
                'canceled' => 0,
                'failed' => 0,
            ];
            $orders?->map(function ($order) use (&$orderStatusArray) {
                if (in_array($order->order_status, ['pending', 'confirmed', 'processing', 'out_for_delivery'])) {
                    $orderStatusArray['ongoing']++;
                } elseif ($order->order_status == 'delivered') {
                    $orderStatusArray['completed']++;
                } else {
                    $orderStatusArray[$order->order_status]++;
                }
                $orderStatusArray['total_order']++;
            });

            $filter = $request['filter'];
            $dateType = $request['date_type'];
            $from = $request['from'];
            $to = $request['to'];
            $orderStatus = $request['order_current_status'] ?? [];
            $filterWhereIn['order_status'] = $orderStatus;

            $orders = $this->orderRepo->getListWhereIn(
                orderBy: ['id' => 'desc'],
                searchValue: $request['searchValue'],
                filters: ['customer_id' => $id, 'is_guest' => '0',  'from' => $request['from'], 'to' => $request['to'],'date_type' => $dateType],
                whereIn: $filterWhereIn,
                relations: ['details', 'customer', 'seller.shop'],
                dataLimit: getWebConfig('pagination_limit'));
            $purchaseLimitSummary = app(CustomerPurchaseLimitService::class)->getLimitSummary($customer);
            $purchaseLimitTransactions = CustomerPurchaseLimitTransaction::with(['subscription', 'package', 'order'])
                ->where('customer_id', $id)
                ->latest('id')
                ->paginate(10, ['*'], 'limit_page')
                ->withQueryString();

            return view('admin-views.customer.customer-view', compact('customer', 'orders', 'orderStatusArray', 'orderStatus', 'filter', 'dateType', 'from', 'to', 'purchaseLimitSummary', 'purchaseLimitTransactions'));
        }
        ToastMagic::error(translate('customer_Not_Found'));
        return back();
    }

    /** Reset a customer password after support has verified account ownership. */
    public function resetPassword(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $id]);
        if (!$customer) {
            ToastMagic::error(translate('customer_Not_Found'));
            return back();
        }

        $this->customerRepo->update(id: $id, data: [
            'password' => Hash::make($request->input('password')),
            'remember_token' => Str::random(60),
        ]);
        $this->customerRepo->deleteAuthAccessTokens(id: $id);

        ToastMagic::success(translate('password_reset_by_administrator'));
        return back();
    }

    public function adjustPurchaseLimit(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'adjustment_type' => 'required|in:add,subtract',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:1000',
        ]);

        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $id]);
        if (!$customer) {
            ToastMagic::error(translate('customer_Not_Found'));
            return back();
        }

        $result = app(CustomerPurchaseLimitService::class)->adjustCustomerLimit(
            customer: $customer,
            amount: (float) $request['amount'],
            type: $request['adjustment_type'],
            note: $request['note'],
            adminId: auth('admin')->id()
        );

        if ($result['status']) {
            ToastMagic::success(translate('purchase_limit_updated_successfully'));
        } else {
            $messages = [
                'active_customer_purchase_package_not_found' => translate('customer_does_not_have_an_active_purchase_package'),
                'adjustment_would_make_limit_less_than_used_amount' => translate('cannot_subtract_more_than_available_purchase_limit'),
                'invalid_adjustment_data' => translate('invalid_data'),
            ];
            ToastMagic::error($messages[$result['message']] ?? translate('update_failed'));
        }

        return back();
    }

    public function exportOrderList(Request $request, $id): BinaryFileResponse
    {
        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $id]);
        $orders = $this->orderRepo->getListWhere(orderBy: ['id' => 'desc'], searchValue: $request['searchValue'], filters: ['customer_id' => $id, 'is_guest' => '0'], dataLimit: 'all');
        $data = [
            'customer' => $customer,
            'searchValue' => $request->get('searchValue'),
            'orders' => $orders
        ];
        return Excel::download(new CustomerOrderListExport($data), 'Customer-Order-List.xlsx');
    }

    /**
     * @param $id
     * @param CustomerService $customerService
     * @return RedirectResponse
     * @throws Exception
     */
    public function deleteCustomer($id, CustomerService $customerService): RedirectResponse
    {
        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $id]);
        if (!$customer) {
            ToastMagic::error(translate('customer_Not_Found'));
            return back();
        }

        if ($this->hasCustomerOperationalHistory((int) $customer->id)) {
            ToastMagic::error(translate('account_cannot_be_deleted_has_operational_history'));
            return back();
        }

        DB::transaction(function () use ($customer, $customerService) {
            $this->deleteActivationCases(
                subjectType: AccountActivationCase::SUBJECT_CUSTOMER,
                subjectId: (int) $customer->id,
            );
            $customerService->deleteImage(data: $customer);
            $this->customerRepo->delete(params: ['id' => $customer->id]);
        });

        ToastMagic::success(translate('customer_deleted_successfully'));
        return back();
    }

    /**
     * An account with financial or ordering evidence must be suspended, not erased.
     * This deliberately checks only tables/columns present in the running installation.
     */
    private function hasCustomerOperationalHistory(int $customerId): bool
    {
        $references = [
            'orders' => ['customer_id'],
            'wallet_transactions' => ['user_id', 'customer_id'],
            'customer_insurance_ledger_entries' => ['customer_id'],
            'customer_insurance_balances' => ['customer_id'],
            'customer_purchase_balances' => ['customer_id'],
            'customer_purchase_limit_transactions' => ['customer_id'],
            'customer_purchase_package_subscriptions' => ['customer_id'],
            'customer_activation_invoices' => ['customer_id'],
            'post_purchase_invoices' => ['customer_id'],
        ];

        foreach ($references as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, $customerId)->exists()) {
                    return true;
                }
            }
        }

        return Schema::hasTable('insurance_balance_actions')
            && Schema::hasColumn('insurance_balance_actions', 'subject_type')
            && Schema::hasColumn('insurance_balance_actions', 'subject_id')
            && DB::table('insurance_balance_actions')
                ->where('subject_type', AccountActivationCase::SUBJECT_CUSTOMER)
                ->where('subject_id', $customerId)
                ->exists();
    }

    private function deleteActivationCases(string $subjectType, int $subjectId): void
    {
        AccountActivationCase::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with('documents')
            ->get()
            ->each(function (AccountActivationCase $case) {
                foreach ($case->documents as $document) {
                    if ($document->storage_path && Storage::disk($document->storage_disk ?: 'public')->exists($document->storage_path)) {
                        Storage::disk($document->storage_disk ?: 'public')->delete($document->storage_path);
                    }
                }

                $case->events()->delete();
                $case->fields()->delete();
                $case->documents()->delete();
                $case->delete();
            });
    }

    public function getSubscriberListView(Request $request): View|RedirectResponse
    {
        $orderBy = $request['sort_by'] ?? 'desc';
        $takeItem = $request->get('choose_first');
        $startDate = '';
        $endDate = '';
        if (isset($request['subscription_date']) && !empty($request['subscription_date'])) {
            $dates = explode(' - ', $request['subscription_date']);
            if (count($dates) !== 2 || !checkDateFormatInMDY($dates[0]) || !checkDateFormatInMDY($dates[1])) {
                ToastMagic::error(translate('Invalid_date_range_format'));
                return back();
            }
            $startDate = Carbon::createFromFormat('m/d/Y', $dates[0])->startOfDay();
            $endDate = Carbon::createFromFormat('m/d/Y', $dates[1])->endOfDay();
        }
        $subscriberList = $this->subscriptionRepo->getListWhereBetween(
            orderBy: ['created_at' => $orderBy],
            searchValue: $request['searchValue'],
            whereBetween: 'created_at',
            whereBetweenFilters: $startDate && $endDate ? [$startDate, $endDate] : [],
            takeItem: $takeItem,
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT),
            appends: $request->all(),
        );
        $totalSubscribers = $this->subscriptionRepo->getListWhere(dataLimit: 'all')->count();
        return view('admin-views.customer.subscriber-list', compact('subscriberList', 'totalSubscribers'));
    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $filters = [
            'is_active' => $request['is_active'] ?? null,
            'order_date' => $request['order_date'],
            'sort_by' => $request['sort_by'] ?? null,
            'avoid_walking_customer' => 1,
        ];
        $takeItem = $request->get('choose_first');

        $orderStartDate = '';
        $orderEndDate = '';
        if (isset($request['order_date'])) {
            $dates = explode(' - ', $request['order_date']);
            $orderStartDate = Carbon::createFromFormat('m/d/Y', $dates[0])->startOfDay();
            $orderEndDate = Carbon::createFromFormat('m/d/Y', $dates[1])->endOfDay();
        }

        $joiningStartDate = '';
        $joiningEndDate = '';
        if (isset($request['customer_joining_date'])) {
            $dates = explode(' - ', $request['customer_joining_date']);
            $joiningStartDate = Carbon::createFromFormat('m/d/Y', $dates[0])->startOfDay();
            $joiningEndDate = Carbon::createFromFormat('m/d/Y', $dates[1])->endOfDay();
        }

        $customers = $this->customerRepo->getListWhereBetween(
            searchValue: $request['searchValue'],
            filters: $filters,
            relations: ['orders'],
            whereBetween: 'created_at',
            whereBetweenFilters: $joiningStartDate && $joiningEndDate ? [$joiningStartDate, $joiningEndDate] : [],
            takeItem: $takeItem,
            dataLimit: 'all',
            appends: $request->all(),
        );
        $status = $request->is_active ?? '';
        $sortBy = $request->sort_by ?? '';
        $chooseFirst = $request->choose_first ?? '';
        $data = [
            'customers' => $customers,
            'status' => $status,
            'sortBy' => $sortBy,
            'chooseFirst' => $chooseFirst,
            'searchValue' => $request->get('searchValue'),
            'orderStartDate' => $orderStartDate,
            'orderEndDate' => $orderEndDate,
            'joiningStartDate' => $joiningStartDate,
            'joiningEndDate' => $joiningEndDate,
        ];
        return Excel::download(new CustomerListExport($data), 'Customers.xlsx');
    }

    public function exportSubscribersList(Request $request): BinaryFileResponse
    {
        $orderBy = $request->get('sort_by', 'desc');
        $takeItem = $request->get('choose_first');
        $startDate = '';
        $endDate = '';
        if (isset($request['subscription_date'])) {
            $dates = explode(' - ', $request['subscription_date']);
            $startDate = Carbon::createFromFormat('m/d/Y', $dates[0])->startOfDay();
            $endDate = Carbon::createFromFormat('m/d/Y', $dates[1])->endOfDay();
        }
        $subscriptionList = $this->subscriptionRepo->getListWhereBetween(
            orderBy: ['created_at' => $orderBy],
            searchValue: $request['searchValue'],
            whereBetween: 'created_at',
            whereBetweenFilters: $startDate && $endDate ? [$startDate, $endDate] : [],
            takeItem: $takeItem,
            dataLimit: 'all',
            appends: $request->all(),
        );
        $sortBy = $request->sort_by ?? '';
        $chooseFirst = $request->choose_first ?? '';
        $data = [
            'subscription' => $subscriptionList,
            'sortBy' => $sortBy,
            'chooseFirst' => $chooseFirst,
            'search' => $request['searchValue'],
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
        return Excel::download(new SubscriberListExport($data), 'Subscriber-list.xlsx');
    }

    public function getCustomerSettingsView(): View
    {
        $wallet = $this->businessSettingRepo->getListWhere(filters: [['type', 'like', 'wallet_%']]);
        $loyaltyPoint = $this->businessSettingRepo->getListWhere(filters: [['type', 'like', 'loyalty_point_%']]);
        $refEarning = $this->businessSettingRepo->getListWhere(filters: [['type', 'like', 'ref_earning_%']]);
        $currencySymbol = $this->currencyRepo->getFirstWhere(['id' => getWebConfig('system_default_currency')]);


        $data = [];
        $data['currency_symbol'] = $currencySymbol['symbol'];

        foreach ($wallet as $setting) {
            $data[$setting->type] = $setting->value;
        }
        foreach ($loyaltyPoint as $setting) {
            $data[$setting->type] = $setting->value;
        }
        foreach ($refEarning as $setting) {
            $data[$setting->type] = $setting->value;
        }

        return view('admin-views.customer.customer-settings', $data);
    }

    public function updateCustomer(CustomerUpdateSettingsRequest $request): View|RedirectResponse
    {
        if (env('APP_MODE') === 'demo') {
            ToastMagic::info(translate('update_option_is_disable_for_demo'));
            return back();
        }

        $data = $this->referByEarnCustomerService->getEarnByReferralData(data: $request->all());
        $this->businessSettingRepo->updateOrInsert(type: 'wallet_status', value: $request->get('customer_wallet', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'loyalty_point_status', value: $request->get('customer_loyalty_point', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'loyalty_point_for_each_order', value: $request->get('loyalty_point_for_each_order', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'loyalty_point_exchange_rate', value: $request->get('loyalty_point_exchange_rate', getWebConfig('loyalty_point_exchange_rate')));
        $this->businessSettingRepo->updateOrInsert(type: 'loyalty_point_item_purchase_point', value: $request->get('item_purchase_point', getWebConfig('loyalty_point_item_purchase_point')));
        $this->businessSettingRepo->updateOrInsert(type: 'loyalty_point_minimum_point', value: $request->get('minimum_transfer_point', getWebConfig('loyalty_point_minimum_point')));
        $this->businessSettingRepo->updateOrInsert(type: 'ref_earning_status', value: $request->get('ref_earning_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'ref_earning_exchange_rate', value: currencyConverter(amount: $request->get('ref_earning_exchange_rate', getWebConfig('ref_earning_exchange_rate'))));
        $this->businessSettingRepo->updateOrInsert(type: 'add_funds_to_wallet', value: $request->get('add_funds_to_wallet', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_extra_credit_status', value: $request->get('customer_extra_credit_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_extra_credit_min_amount', value: currencyConverter(amount: $request->get('customer_extra_credit_min_amount', 50)));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_extra_credit_step_amount', value: currencyConverter(amount: $request->get('customer_extra_credit_step_amount', 100)));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_extra_credit_rate', value: $request->get('customer_extra_credit_rate', 10));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_extra_credit_max_amount', value: currencyConverter(amount: $request->get('customer_extra_credit_max_amount', 0)));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_extra_credit_rounding_rule', value: $request->get('customer_extra_credit_rounding_rule', 'ceil_step'));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_monthly_insurance_status', value: $request->get('customer_monthly_insurance_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_monthly_insurance_amount', value: currencyConverter(amount: $request->get('customer_monthly_insurance_amount', 0)));
        $insuranceDiscountType = $request->get('customer_monthly_insurance_first_discount_type', 'none');
        $insuranceDiscountValue = $request->get('customer_monthly_insurance_first_discount_value', 0);
        $this->businessSettingRepo->updateOrInsert(type: 'customer_monthly_insurance_first_discount_type', value: $insuranceDiscountType);
        $this->businessSettingRepo->updateOrInsert(
            type: 'customer_monthly_insurance_first_discount_value',
            value: $insuranceDiscountType === 'fixed' ? currencyConverter(amount: $insuranceDiscountValue) : $insuranceDiscountValue
        );
        $this->businessSettingRepo->updateOrInsert(type: 'customer_monthly_insurance_period_days', value: $request->get('customer_monthly_insurance_period_days', 30));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_activation_hold_message', value: $request->get('customer_activation_hold_message'));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_three_step_shipping_status', value: $request->get('customer_three_step_shipping_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_shipping_sigma_status', value: $request->get('customer_shipping_sigma_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_shipping_sigma_title', value: $request->get('customer_shipping_sigma_title', 'Sigma Shipping'));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_shipping_sigma_cost', value: currencyConverter(amount: $request->get('customer_shipping_sigma_cost', 0)));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_shipping_normal_status', value: $request->get('customer_shipping_normal_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_shipping_normal_title', value: $request->get('customer_shipping_normal_title', 'Normal Shipping'));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_shipping_normal_cost', value: currencyConverter(amount: $request->get('customer_shipping_normal_cost', 0)));
        foreach (['sigma', 'normal'] as $shippingPromiseKey) {
            $defaultMin = $shippingPromiseKey === 'sigma' ? 1 : 4;
            $defaultMax = $shippingPromiseKey === 'sigma' ? 1 : 7;
            $this->businessSettingRepo->updateOrInsert(
                type: "shipping_promise_{$shippingPromiseKey}_mode",
                value: $request->get("shipping_promise_{$shippingPromiseKey}_mode", $shippingPromiseKey === 'sigma' ? 'fixed' : 'range')
            );
            $this->businessSettingRepo->updateOrInsert(
                type: "shipping_promise_{$shippingPromiseKey}_min_days",
                value: $request->get("shipping_promise_{$shippingPromiseKey}_min_days", $defaultMin)
            );
            $this->businessSettingRepo->updateOrInsert(
                type: "shipping_promise_{$shippingPromiseKey}_max_days",
                value: $request->get("shipping_promise_{$shippingPromiseKey}_max_days", $defaultMax)
            );
        }
        $this->businessSettingRepo->updateOrInsert(
            type: 'commerce_working_days',
            value: json_encode($request->input('commerce_working_days', [0, 1, 2, 3, 4]))
        );
        if (\Illuminate\Support\Facades\Schema::hasTable('business_calendar_holidays')) {
            $holidayLines = preg_split('/\r\n|\r|\n/', (string) $request->input('commerce_holidays', ''));
            $holidayDates = [];
            foreach ($holidayLines as $holidayLine) {
                [$holidayDate, $holidayName] = array_pad(array_map('trim', explode('|', $holidayLine, 2)), 2, 'Official holiday');
                if ($holidayDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
                    $holidayDates[] = $holidayDate;
                    BusinessCalendarHoliday::updateOrCreate(
                        ['holiday_date' => $holidayDate],
                        ['name' => $holidayName ?: 'Official holiday', 'active' => true, 'created_by_admin_id' => auth('admin')->id()]
                    );
                }
            }
            BusinessCalendarHoliday::whereNotIn('holiday_date', $holidayDates ?: ['1900-01-01'])->update(['active' => false]);
        }
        $productShippingExtraChargeType = $request->get('customer_product_shipping_extra_charge_type', 'percent');
        $productShippingExtraChargeValue = $request->get('customer_product_shipping_extra_charge_value', 0);
        // The quantity rule is applied once per seller cart group by CustomerThreeStepShippingService.
        $this->businessSettingRepo->updateOrInsert(type: 'customer_product_shipping_quantity_threshold', value: $request->get('customer_product_shipping_quantity_threshold', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'customer_product_shipping_extra_charge_type', value: $productShippingExtraChargeType);
        $this->businessSettingRepo->updateOrInsert(
            type: 'customer_product_shipping_extra_charge_value',
            value: $productShippingExtraChargeType === 'fixed'
                ? currencyConverter(amount: $productShippingExtraChargeValue)
                : $productShippingExtraChargeValue
        );
        app(CustomerThreeStepShippingService::class)->syncMethods();
        $this->businessSettingRepo->updateOrInsert(type: 'customer_manual_transfer_method_name', value: $request->get('customer_manual_transfer_method_name', 'Manual Transfer / Auto Payment Form'));
        $this->syncManualTransferOfflineMethod($request->get('customer_manual_transfer_method_name', 'Manual Transfer / Auto Payment Form'));
        $this->businessSettingRepo->updateOrInsert(type: 'ref_earning_customer', value: json_encode($data));
        if ($request->has('minimum_add_fund_amount') && $request->has('maximum_add_fund_amount')) {
            if ($request['maximum_add_fund_amount'] > $request['minimum_add_fund_amount']) {
                $this->businessSettingRepo->updateOrInsert(type: 'minimum_add_fund_amount', value: currencyConverter(amount: $request->get('minimum_add_fund_amount', 1)));
                $this->businessSettingRepo->updateOrInsert(type: 'maximum_add_fund_amount', value: currencyConverter(amount: $request->get('maximum_add_fund_amount', 0)));
            } else {
                ToastMagic::error(translate('minimum_amount_cannot_be_greater_than_maximum_amount'));
                return back();
            }
        }

        ToastMagic::success(translate('customer_settings_updated_successfully'));
        return back();
    }

    private function syncManualTransferOfflineMethod(string $methodName): void
    {
        OfflinePaymentMethod::updateOrCreate(
            ['method_name' => $methodName],
            [
                'method_fields' => [
                    ['input_name' => 'wallet_or_instapay_account', 'input_data' => 'Set wallet or InstaPay account from admin panel'],
                    ['input_name' => 'instructions', 'input_data' => 'Transfer the amount then submit sender number and payment screenshot.'],
                ],
                'method_informations' => [
                    ['customer_input' => 'sender_wallet_or_phone', 'customer_placeholder' => 'Sender wallet / phone number', 'is_required' => 1],
                    ['customer_input' => 'sender_name', 'customer_placeholder' => 'Sender name', 'is_required' => 0],
                    ['customer_input' => 'payment_screenshot', 'customer_placeholder' => 'Payment screenshot', 'is_required' => 1],
                ],
                'status' => 1,
            ]
        );
    }

    public function getCustomerList(Request $request): JsonResponse
    {
        $allCustomer = ['id' => 'all', 'text' => 'All Customer'];
        $customers = $this->customerRepo->getCustomerNameList(request: $request)->toArray();
        array_unshift($customers, $allCustomer);
        return response()->json($customers);
    }

    public function getCustomerListWithoutAllCustomerName(Request $request): JsonResponse
    {
        $customers = $this->customerRepo->getCustomerNameList(request: $request)->toArray();
        return response()->json($customers);
    }

    public function add(CustomerRequest $request, CustomerService $customerService): JsonResponse
    {
        $token = Str::random(120);
        $this->passwordResetRepo->add($this->passwordResetService->getAddData(identity: $request['phone'], token: $token, userType: 'customer'));
        $this->customerRepo->add($customerService->getCustomerData(request: $request));
        $customer = $this->customerRepo->getFirstWhere(params: ['email' => $request['email']]);
        $this->shippingAddressRepo->add($this->shippingAddressService->getAddAddressData(request: $request, customerId: $customer['id'], addressType: 'home'));
        $resetRoute = route('customer.auth.recover-password');
        $data = [
            'userName' => $request['f_name'],
            'userType' => 'customer',
            'templateName' => 'registration-from-pos',
            'subject' => translate('Customer_Registration_Successfully_Completed'),
            'title' => translate('welcome_to') . ' ' . getWebConfig(name: 'company_name') . '!',
            'resetPassword' => $resetRoute,
            'message' => translate('thank_you_for_joining') . ' ' . getWebConfig(name: 'company_name') . '.' . translate('if_you_want_to_become_a_registered_customer_then_reset_your_password_below_by_using_this_phone') . ' ' . ($request['phone']) . '.' . translate('then_you’ll_be_able_to_explore_the_website_and_app_as_a_registered_customer') . '.',
        ];
        event(new CustomerRegistrationEvent(email: $request['email'], data: $data));
       return response()->json(['success' => true, 'message' => translate('customer_added_successfully')]);
    }


    public function updateProfile(CustomerProfileUpdateRequest $request, CustomerService $customerService): RedirectResponse
    {
        $customer = $this->customerRepo->getFirstWhere(params: ['id' => $request['id']]);
        $this->customerRepo->updateWhere(['id' => $request['id']], data: $customerService->getCustomerProfileUpdateData(request: $request, customer: $customer));
        ToastMagic::success(translate('Update_successfully'));
        return redirect()->back();
    }

    /** Send a notification only to customers selected from the customer list. */
    public function notifySelected(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:1000'],
            'customer_ids' => ['required', 'array', 'min:1', 'max:100'],
            'customer_ids.*' => ['integer', 'distinct'],
        ]);

        $customers = User::query()
            ->whereIn('id', $data['customer_ids'])
            ->where(function ($query) {
                $query->whereNull('email')->orWhere('email', '!=', 'walking@customer.com');
            })
            ->whereNotNull('cm_firebase_token')
            ->where('cm_firebase_token', '!=', '')
            ->get(['id', 'cm_firebase_token']);

        if ($customers->isEmpty()) {
            ToastMagic::error(translate('no_customer_device_available'));
            return back();
        }

        $sentCount = 0;
        foreach ($customers as $customer) {
            try {
                $this->sendPushNotificationToDevice($customer->cm_firebase_token, [
                    'title' => $data['title'],
                    'description' => $data['message'],
                    'image' => '',
                    'order_id' => '',
                    'type' => 'admin_customer_notification',
                    'notification_from' => 'admin',
                ]);
                $sentCount++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($sentCount === 0) {
            ToastMagic::error(translate('no_customer_device_available'));
            return back();
        }

        ToastMagic::success(translate('customer_notification_sent_successfully'));
        return back();
    }
}
