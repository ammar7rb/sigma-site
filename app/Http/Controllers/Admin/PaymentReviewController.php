<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SellerInsurance;
use App\Models\SellerOrderInsurance;
use App\Models\SellerPackageSubscription;
use App\Services\SellerInsuranceService;
use App\Services\SellerOrderInsuranceService;
use App\Services\PostPurchaseInvoiceService;
use App\Services\SellerPackagePurchaseService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PaymentReviewController extends Controller
{
    public function __construct(
        private readonly SellerPackagePurchaseService $packageService,
        private readonly SellerInsuranceService $insuranceService,
        private readonly SellerOrderInsuranceService $sellerOrderInsuranceService,
        private readonly PostPurchaseInvoiceService $postPurchaseInvoices,
    ) {
    }

    public function index(Request $request): View
    {
        $rows = $this->rows($request)->sortByDesc('created_at')->values();
        $perPage = (int) (getWebConfig(name: 'pagination_limit') ?: 20);
        $page = max(1, (int) $request->get('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('admin-views.payment-reviews.index', ['reviews' => $paginator, 'pendingCount' => $rows->where('status', 'pending')->count()]);
    }

    public function approve(Request $request, string $type, int|string $id): RedirectResponse
    {
        $request->validate(['payment_reference' => 'nullable|string|max:191', 'review_note' => 'nullable|string|max:1000']);
        try {
            if ($type === 'vendor_package') {
                $subscription = SellerPackageSubscription::query()->where('payment_method', 'offline_payment')->where('payment_status', 'unpaid')->where('status', SellerPackageSubscription::STATUS_PENDING_REVIEW)->findOrFail($id);
                $result = $this->packageService->markPaid($subscription, ['payment_method' => 'offline_payment', 'transaction_id' => $request->get('payment_reference') ?: 'unified-review-'.$subscription->id, 'payment_amount' => (float) $subscription->paid_package_price, 'currency_code' => getCurrencyCode(type: 'default')], auth('admin')->id(), $request->get('review_note'));
                if (($result['status'] ?? 0) !== 1) throw new DomainException($result['message'] ?? 'seller_package_approval_failed');
            } elseif ($type === 'vendor_insurance') {
                $insurance = SellerInsurance::query()->where('payment_method', 'offline_payment')->where('payment_status', 'unpaid')->where('status', SellerInsurance::STATUS_PENDING_REVIEW)->findOrFail($id);
                $result = $this->insuranceService->markPaid($insurance, ['payment_method' => 'offline_payment', 'transaction_id' => $request->get('payment_reference') ?: 'unified-review-'.$insurance->id, 'payment_amount' => (float) $insurance->amount, 'currency_code' => getCurrencyCode(type: 'default')], auth('admin')->id(), $request->get('review_note'));
                if (($result['status'] ?? 0) !== 1) throw new DomainException($result['message'] ?? 'seller_insurance_approval_failed');
            } elseif ($type === 'vendor_order_insurance') {
                $insurance = SellerOrderInsurance::query()->where('payment_method', 'offline_payment')->where('payment_status', 'unpaid')->where('status', SellerOrderInsurance::STATUS_PENDING_REVIEW)->findOrFail($id);
                $this->sellerOrderInsuranceService->markPaid($insurance, 'offline_payment', $request->get('payment_reference') ?: 'unified-order-insurance-'.$insurance->id, auth('admin')->id(), $request->get('review_note'));
            } elseif ($type === 'customer_order') {
                $order = Order::query()->where('payment_method', 'offline_payment')->where('payment_status', 'unpaid')->findOrFail($id);
                \App\Utils\OrderManager::getStockUpdateOnOrderStatusChange($order, 'canceled');
                $order->update([
                    'payment_status' => 'paid',
                    'transaction_ref' => $request->get('payment_reference') ?: ('offline-review-'.$order->id),
                ]);
                $this->postPurchaseInvoices->createForPaidOrderGroup([$order->id]);
            } else {
                throw new DomainException('offline_payment_review_not_found');
            }
            ToastMagic::success(translate('offline_payment_approved_successfully'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    public function reject(Request $request, string $type, int|string $id): RedirectResponse
    {
        $request->validate(['review_note' => 'required|string|max:1000']);
        try {
            if ($type === 'vendor_package') {
                $subscription = SellerPackageSubscription::query()->where('payment_method', 'offline_payment')->where('payment_status', 'unpaid')->where('status', SellerPackageSubscription::STATUS_PENDING_REVIEW)->findOrFail($id);
                $this->packageService->rejectOfflinePayment($subscription, auth('admin')->id(), $request->get('review_note'));
            } elseif ($type === 'vendor_insurance') {
                $insurance = SellerInsurance::query()->where('payment_method', 'offline_payment')->where('payment_status', 'unpaid')->where('status', SellerInsurance::STATUS_PENDING_REVIEW)->findOrFail($id);
                $this->insuranceService->rejectOfflinePayment($insurance, auth('admin')->id(), $request->get('review_note'));
            } elseif ($type === 'vendor_order_insurance') {
                $insurance = SellerOrderInsurance::query()->where('payment_method', 'offline_payment')->where('payment_status', 'unpaid')->where('status', SellerOrderInsurance::STATUS_PENDING_REVIEW)->findOrFail($id);
                $this->sellerOrderInsuranceService->rejectOfflinePayment($insurance, auth('admin')->id(), $request->get('review_note'));
            } elseif ($type === 'customer_order') {
                $order = Order::query()->where('payment_method', 'offline_payment')->where('payment_status', 'unpaid')->findOrFail($id);
                $order->update([
                    'order_status' => 'canceled',
                    'activation_status' => 'offline_payment_rejected',
                    'order_note' => trim(($order->order_note ? $order->order_note."\n" : '').'Offline payment rejected: '.$request->get('review_note')),
                ]);
            } else {
                throw new DomainException('offline_payment_review_not_found');
            }
            ToastMagic::success(translate('offline_payment_rejected_successfully'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    private function rows(Request $request): Collection
    {
        $rows = collect();
        $audience = $request->get('audience');
        $kind = $request->get('kind');
        $status = $request->get('status');
        $search = trim((string) $request->get('searchValue'));

        if (!$audience || $audience === 'vendor') {
            if (!$kind || $kind === 'package') {
                SellerPackageSubscription::query()->with(['seller:id,f_name,l_name,email,phone', 'package:id,name'])->where('payment_method', 'offline_payment')->when($status === 'pending', fn ($q) => $q->where('status', SellerPackageSubscription::STATUS_PENDING_REVIEW))->when($status === 'approved', fn ($q) => $q->where('status', SellerPackageSubscription::STATUS_ACTIVE))->when($status === 'rejected', fn ($q) => $q->where('status', SellerPackageSubscription::STATUS_REJECTED))->when(!$status, fn ($q) => $q->whereIn('status', [SellerPackageSubscription::STATUS_PENDING_REVIEW, SellerPackageSubscription::STATUS_ACTIVE, SellerPackageSubscription::STATUS_REJECTED]))->latest('id')->get()->each(function ($subscription) use (&$rows, $search) {
                    $text = strtolower(implode(' ', [$subscription->package_name, $subscription->payment_reference, $subscription->seller?->email, $subscription->seller?->phone]));
                    if ($search !== '' && !str_contains($text, strtolower($search))) return;
                    $rows->push($this->normalize($subscription, 'vendor_package', 'package', 'vendor', $subscription->seller, $subscription->package_name, $subscription->paid_package_price, $subscription->metadata['offline_payment'] ?? null));
                });
            }
            if (!$kind || $kind === 'insurance') {
                SellerInsurance::query()->with(['seller:id,f_name,l_name,email,phone'])->where('payment_method', 'offline_payment')->when($status === 'pending', fn ($q) => $q->where('status', SellerInsurance::STATUS_PENDING_REVIEW))->when($status === 'approved', fn ($q) => $q->where('status', SellerInsurance::STATUS_PAID))->when($status === 'rejected', fn ($q) => $q->where('status', SellerInsurance::STATUS_REJECTED))->when(!$status, fn ($q) => $q->whereIn('status', [SellerInsurance::STATUS_PENDING_REVIEW, SellerInsurance::STATUS_PAID, SellerInsurance::STATUS_REJECTED]))->latest('id')->get()->each(function ($insurance) use (&$rows, $search) {
                    $text = strtolower(implode(' ', [$insurance->transaction_id, $insurance->payment_reference, $insurance->seller?->email, $insurance->seller?->phone]));
                    if ($search !== '' && !str_contains($text, strtolower($search))) return;
                    $rows->push($this->normalize($insurance, 'vendor_insurance', 'insurance', 'vendor', $insurance->seller, translate('seller_insurance'), $insurance->amount, $insurance->metadata['offline_payment'] ?? null));
                });
                SellerOrderInsurance::query()->with(['seller:id,f_name,l_name,email,phone'])->where('payment_method', 'offline_payment')->when($status === 'pending', fn ($q) => $q->where('status', SellerOrderInsurance::STATUS_PENDING_REVIEW))->when($status === 'approved', fn ($q) => $q->where('status', SellerOrderInsurance::STATUS_PAID))->when(!$status, fn ($q) => $q->whereIn('status', [SellerOrderInsurance::STATUS_PENDING_REVIEW, SellerOrderInsurance::STATUS_PAID]))->latest('id')->get()->each(function ($insurance) use (&$rows, $search) {
                    $text = strtolower(implode(' ', [$insurance->order_id, $insurance->payment_reference, $insurance->seller?->email, $insurance->seller?->phone]));
                    if ($search !== '' && !str_contains($text, strtolower($search))) return;
                    $rows->push($this->normalize($insurance, 'vendor_order_insurance', 'insurance', 'vendor', $insurance->seller, translate('seller_order_insurance') . ' #' . $insurance->order_id, $insurance->amount, $insurance->metadata['offline_payment'] ?? null));
                });
            }
        }
        if ((!$audience || $audience === 'customer') && (!$kind || $kind === 'order')) {
            Order::query()
                ->with(['customer:id,f_name,l_name,email,phone', 'offlinePayments'])
                ->where('payment_method', 'offline_payment')
                ->when($status === 'pending', fn ($q) => $q->where('payment_status', 'unpaid'))
                ->when($status === 'approved', fn ($q) => $q->where('payment_status', 'paid'))
                ->when($status === 'rejected', fn ($q) => $q->where('activation_status', 'offline_payment_rejected'))
                ->when(!$status, fn ($q) => $q->where(function ($q) {
                    $q->whereIn('payment_status', ['unpaid', 'paid'])
                        ->orWhere('activation_status', 'offline_payment_rejected');
                }))
                ->latest('id')
                ->get()
                ->each(function (Order $order) use (&$rows, $search) {
                    $text = strtolower(implode(' ', [$order->id, $order->customer?->email, $order->customer?->phone]));
                    if ($search !== '' && !str_contains($text, strtolower($search))) return;
                    $status = $order->activation_status === 'offline_payment_rejected' ? 'rejected' : ($order->payment_status === 'paid' ? 'approved' : 'pending');
                    $rows->push([
                        'id' => $order->id,
                        'type' => 'customer_order',
                        'kind' => 'order',
                        'audience' => 'customer',
                        'status' => $status,
                        'actor' => $order->customer,
                        'label' => 'Order #'.$order->id,
                        'amount' => (float) $order->order_amount,
                        'currency' => getCurrencyCode(type: 'default'),
                        'reference' => $order->transaction_ref ?: 'ORD-'.$order->id,
                        'offline' => $order->offlinePayments?->payment_info,
                        'created_at' => $order->created_at,
                    ]);
                });
        }
        return $rows;
    }

    private function normalize($record, string $type, string $kind, string $audience, $actor, string $label, float $amount, ?array $offline): array
    {
        $pending = in_array($record->status, ['pending_offline_review', SellerPackageSubscription::STATUS_PENDING_REVIEW, SellerInsurance::STATUS_PENDING_REVIEW, SellerOrderInsurance::STATUS_PENDING_REVIEW], true);
        return ['id' => $record->id, 'type' => $type, 'kind' => $kind, 'audience' => $audience, 'status' => $pending ? 'pending' : (($record->status === 'rejected') ? 'rejected' : 'approved'), 'actor' => $actor, 'label' => $label, 'amount' => $amount, 'currency' => $record->currency_code ?? getCurrencyCode(type: 'default'), 'reference' => $record->invoice_no ?? $record->payment_reference ?? $record->transaction_id, 'offline' => $offline, 'created_at' => $record->created_at];
    }

}
