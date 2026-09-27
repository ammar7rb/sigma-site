<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\AddFundBonusCategories;
use App\Models\WalletTransaction;
use App\Models\PostPurchaseInvoice;
use App\Services\CustomerInsuranceBalanceService;
use App\Utils\Helpers;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserWalletController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $customer = $request->user('api');
        $customerId = (int) $customer->id;
        $insurance = app(CustomerInsuranceBalanceService::class);
        $canDeposit = getWebConfig('add_funds_to_wallet') == 1;
        $pendingInvoicesQuery = PostPurchaseInvoice::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', [PostPurchaseInvoice::STATUS_PENDING, PostPurchaseInvoice::STATUS_AWAITING_REVIEW]);
        $pendingInvoicesCount = (clone $pendingInvoicesQuery)->count();
        return response()->json([
            'purchase_balance' => (float) $customer->wallet_balance,
            'insurance' => $insurance->summary($customerId),
            'purchase_enabled' => getWebConfig('wallet_status') == 1,
            'insurance_enabled' => (int) (getWebConfig('customer_order_insurance_status') ?? 0) === 1,
            'tax_enabled' => app(\App\Services\WorkflowFeatureService::class)->postPurchaseTaxInvoiceEnabled(),
            'pending_invoices_count' => $pendingInvoicesCount,
            'pending_invoices' => $pendingInvoicesQuery->with('order:id,order_group_id,order_amount')
                ->latest('id')->limit(5)->get()->map(fn (PostPurchaseInvoice $invoice) => [
                    'id' => $invoice->id,
                    'order_id' => $invoice->order_id,
                    'order_group_id' => $invoice->order?->order_group_id,
                    'status' => $invoice->status,
                    'insurance_amount' => (float) $invoice->insurance_amount,
                    'tax_amount' => (float) $invoice->tax_amount,
                    'total_amount' => (float) $invoice->total_amount,
                    'paid_amount' => (float) $invoice->paid_amount,
                    'amount_due' => max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount),
                    'payment_due_at' => $invoice->payment_due_at?->toIso8601String(),
                ])->values(),
            'offline_methods' => $canDeposit && (getWebConfig('offline_payment')['status'] ?? 0)
                ? \App\Models\OfflinePaymentMethod::where('status', 1)->get(['id', 'method_name', 'payment_channel', 'method_fields', 'method_informations']) : [],
            'digital_methods' => $canDeposit && (getWebConfig('digital_payment')['status'] ?? 0)
                ? \App\Utils\payment_gateways()->map(fn ($gateway) => ['key' => $gateway->key_name])->values() : [],
            'deposits' => \App\Models\CustomerBalanceDeposit::where('customer_id', $customerId)->latest('id')
                ->paginate(20, ['id', 'wallet_type', 'amount', 'status', 'method_name', 'payment_reference', 'review_note', 'created_at']),
            'purchase_entries' => WalletTransaction::where('user_id', $customerId)->latest('id')
                ->paginate(20, ['credit', 'debit', 'balance', 'created_at']),
            'insurance_entries' => $insurance->recentEntries($customerId)->map(fn ($entry) => [
                'order_id' => $entry->order_id, 'entry_type' => $entry->entry_type,
                'credit' => $entry->credit, 'debit' => $entry->debit, 'created_at' => $entry->created_at,
            ])->values(),
            'orders' => \App\Models\Order::where('customer_id', $customerId)->where('is_guest', 0)
                ->with(['insurance', 'postPurchaseInvoice'])->latest('id')->paginate(20)->through(fn ($order) => [
                    'id' => $order->id, 'amount' => $order->order_amount,
                    'payment_status' => $order->payment_status, 'created_at' => $order->created_at,
                    'insurance' => $order->insurance ? collect($order->insurance->toArray())->only([
                        'amount', 'insurance_amount', 'status', 'maturity_at', 'matures_at', 'released_at',
                    ]) : ($order->postPurchaseInvoice ? [
                        'amount' => $order->postPurchaseInvoice->insurance_amount,
                        'status' => $order->postPurchaseInvoice->status, 'matures_at' => null,
                    ] : null),
                ]),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        $walletStatus = getWebConfig(name: 'wallet_status');

        if ($walletStatus == 1) {
            $user = $request->user();
            $totalWalletBalance = $user->wallet_balance;
            $insuranceBalanceService = app(CustomerInsuranceBalanceService::class);
            $insuranceBalanceSummary = $insuranceBalanceService->summary((int) $user->id);
            $types = json_decode($request->get('transaction_types', ''), true) ?? [];
            $transactionTypes = $this->getSelectTransactionTypes(types: $types);
            if (request()->has('start_date') && request()->has('end_date') && !checkDateFormatInMDY($request['start_date']) && !checkDateFormatInMDY($request['end_date'])) {
                $startDate = Carbon::createFromFormat('m/d/Y h:i:s a', $request['start_date'])->format('Y-m-d') . ' 00:00:00';
                $endDate = Carbon::createFromFormat('m/d/Y h:i:s a', $request['end_date'])->format('Y-m-d') . ' 23:59:59';
            } else {
                $startDate = '';
                $endDate = '';
            }

            $walletTransactionList = WalletTransaction::where(['user_id' => $user->id])
                ->when($request->has('filter_by') && in_array($request['filter_by'], ['debit', 'credit']), function ($query) use ($request) {
                    $query->when($request['filter_by'] == 'debit', function ($query) {
                        $query->where('debit', '!=', 0);
                    })->when($request['filter_by'] == 'credit', function ($query) {
                        $query->where('debit', '=', 0);
                    });
                })
                ->when(!empty($startDate) && !empty($endDate), function ($query) use ($startDate, $endDate) {
                    return $query->whereBetween('created_at', [$startDate, $endDate]);
                })
                ->when(!empty($transactionTypes) || in_array('added_via_payment_method', $types) || in_array('earned_by_referral', $types), function ($query) use ($transactionTypes, $types) {
                    $query->where(function ($subResult) use ($transactionTypes, $types) {
                        if (!empty($transactionTypes)) {
                            $subResult->whereIn('transaction_type', $transactionTypes);
                        }
                        if (in_array('added_via_payment_method', $types)) {
                            $subResult->orWhere('reference', 'add_funds_to_wallet');
                        }
                        if (in_array('earned_by_referral', $types)) {
                            $subResult->orWhere('reference', 'earned_by_referral');
                        }
                    });
                })
                ->latest()
                ->paginate($request['limit'], ['*'], 'page', $request['offset']);

            return response()->json([
                'limit' => (integer)$request['limit'],
                'offset' => (integer)$request['offset'],
                'total_wallet_balance' => $totalWalletBalance,
                'insurance_available_balance' => $insuranceBalanceSummary['available_balance'],
                'insurance_held_balance' => $insuranceBalanceSummary['held_balance'],
                'insurance_next_maturity_at' => $insuranceBalanceSummary['next_maturity_at'],
                'insurance_ledger_entries' => $insuranceBalanceService->recentEntries((int) $user->id)
                    ->map(fn ($entry) => [
                        'id' => $entry->id,
                        'order_id' => $entry->order_id,
                        'entry_type' => $entry->entry_type,
                        'credit' => $entry->credit,
                        'debit' => $entry->debit,
                        'created_at' => $entry->created_at,
                    ])->values(),
                'total_size' => $walletTransactionList->total(),
                'wallet_transaction_list' => $walletTransactionList->items(),
                'filter_by' => $request['filter_by'],
                'start_date' => $request['start_date'],
                'end_date' => $request['end_date'],
                'transaction_types' => (array)$types,
            ], 200);
        } else {
            return response()->json(['message' => translate('access_denied!')], 422);
        }
    }

    public function bonus_list(Request $request): JsonResponse
    {
        $addFundBonusCategories = AddFundBonusCategories::active()
            ->whereDate('start_date_time', '<=', now())
            ->whereDate('end_date_time', '>=', now())
            ->get();
        return response()->json(['bonus_list' => $addFundBonusCategories], 200);
    }

    public function getSelectTransactionTypes($types): array
    {
        $typeMapping = [
            'order_refund' => 'order_refund',
            'order_place' => 'order_place',
            'loyalty_point' => 'loyalty_point',
            'add_fund' => 'add_fund',
            'add_fund_by_admin' => 'add_fund_by_admin',
            'due_payment_for_order' => 'due_payment_for_order',
            'return_order_amount_by_admin' => 'return_order_amount_by_admin',
        ];

        foreach ($typeMapping as $key => $value) {
            if (in_array($key, $types)) {
                $transactionTypes[] = $value;
            }
        }

        return $transactionTypes ?? [];
    }
}
