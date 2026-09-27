<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\Controller;
use App\Models\SellerBalanceDeposit;
use App\Models\SellerLedgerEntry;
use App\Models\SellerSettlement;
use App\Services\SellerSettlementService;
use Carbon\Carbon;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use App\Models\WithdrawRequest;
use App\Services\SellerLedgerService;
use App\Services\FinancialReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SellerFinancialCenterController extends Controller
{
    public function __construct(
        private readonly SellerLedgerService $ledger,
        private readonly SellerSettlementService $settlements,
        private readonly FinancialReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): View
    {
        $sellerId = $request->integer('seller_id') ?: null;
        $ledgerTotals = array_fill_keys([
            SellerLedgerService::SALES_TOTAL,
            SellerLedgerService::PENDING,
            SellerLedgerService::AVAILABLE,
            SellerLedgerService::OPERATING,
            SellerLedgerService::ORDER_INSURANCE_CREDIT,
            SellerLedgerService::PENDING_WITHDRAW,
            SellerLedgerService::WITHDRAWN,
        ], 0.0);
        $totals = SellerLedgerEntry::query()
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->select('bucket')
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS balance")
            ->groupBy('bucket')
            ->pluck('balance', 'bucket');
        foreach ($ledgerTotals as $bucket => $value) {
            $ledgerTotals[$bucket] = round((float) ($totals[$bucket] ?? 0), 12);
        }

        $depositSummary = SellerBalanceDeposit::query()
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->selectRaw('status, source, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('status', 'source')
            ->get()
            ->groupBy('status')
            ->map(fn ($rows) => [
                'count' => (int) $rows->sum('count'),
                'amount' => (float) $rows->sum('amount'),
            ]);

        $withdrawSummary = WithdrawRequest::query()
            ->whereNotNull('seller_id')
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->selectRaw('approved, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('approved')
            ->get()
            ->keyBy('approved');

        $entries = SellerLedgerEntry::query()
            ->with('seller:id,f_name,l_name,email')
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->when($request->filled('bucket'), fn ($query) => $query->where('bucket', $request->get('bucket')))
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', 'like', '%'.$request->get('event_type').'%'))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->get('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->get('to')))
            ->latest('id')
            ->paginate(20, ['*'], 'entries_page')
            ->appends($request->query());

        $settlementQuery = SellerSettlement::query()
            ->with(['seller:id,f_name,l_name,email', 'order:id,order_amount,order_status'])
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->when($request->filled('settlement_status'), fn ($query) => $query->where('status', $request->get('settlement_status')))
            ->when($request->boolean('overdue'), fn ($query) => $query->where('status', SellerSettlement::STATUS_HELD)->where('due_at', '<', now()))
            ->when($request->filled('settlement_q'), function ($query) use ($request) {
                $term = '%'.$request->get('settlement_q').'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('order_id', 'like', $term)
                        ->orWhereHas('seller', fn ($seller) => $seller->where('email', 'like', $term));
                });
            })
            ->latest('id');

        $settlementSummary = [
            'held' => $this->settlementCount(SellerSettlement::STATUS_HELD, $sellerId),
            'disputed' => $this->settlementCount(SellerSettlement::STATUS_DISPUTED, $sellerId),
            'overdue' => SellerSettlement::query()->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))->where('status', SellerSettlement::STATUS_HELD)->where('due_at', '<', now())->count(),
            'escalated' => SellerSettlement::query()->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))->whereNotNull('escalated_at')->count(),
            'due_soon' => SellerSettlement::query()->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))->where('status', SellerSettlement::STATUS_HELD)->whereBetween('due_at', [now(), now()->addDays(5)])->count(),
            'released' => $this->settlementCount(SellerSettlement::STATUS_RELEASED, $sellerId),
            'reversed' => $this->settlementCount(SellerSettlement::STATUS_REVERSED, $sellerId),
        ];

        return view('admin-views.vendor.financial-center.index', [
            'ledgerTotals' => $ledgerTotals,
            'depositSummary' => $depositSummary,
            'withdrawSummary' => $withdrawSummary,
            'entries' => $entries,
            'recentDeposits' => SellerBalanceDeposit::query()
                ->with('seller:id,f_name,l_name,email')
                ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
                ->latest('id')
                ->limit(10)
                ->get(),
            'recentWithdrawals' => WithdrawRequest::query()
                ->whereNotNull('seller_id')
                ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
                ->with('seller:id,f_name,l_name,email')
                ->latest('id')
                ->limit(10)
                ->get(),
            'settlements' => $settlementQuery->paginate(20, ['*'], 'settlements_page')->appends($request->query()),
            'settlementSummary' => $settlementSummary,
            'reconciliation' => $this->reconciliation->summary($sellerId, $request->get('from'), $request->get('to')),
        ]);
    }

    public function updateSettlementDueDate(Request $request, int|string $id)
    {
        $request->validate(['due_at' => 'required|date', 'review_note' => 'nullable|string|max:1000']);
        try {
            $settlement = $this->settlements->setDueAt(
                (int) $id,
                Carbon::parse($request->get('due_at')),
                auth('admin')->id(),
                $request->get('review_note'),
            );
            if (! $settlement) {
                throw new \DomainException('seller_settlement_not_editable');
            }
            ToastMagic::success(translate('seller_settlement_due_date_updated'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    public function releaseSettlement(int|string $id)
    {
        try {
            if (! $this->settlements->release((int) $id)) {
                throw new \DomainException('seller_settlement_not_due');
            }
            ToastMagic::success(translate('seller_settlement_released'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    public function disputeSettlement(Request $request, int|string $id)
    {
        $request->validate(['reason' => 'required|string|max:1000']);
        try {
            if (! $this->settlements->dispute((int) $id, $request->get('reason'))) {
                throw new \DomainException('seller_settlement_not_held');
            }
            ToastMagic::success(translate('seller_settlement_disputed'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    public function reverseSettlement(Request $request, int|string $id)
    {
        $request->validate(['reason' => 'required|string|max:1000']);
        try {
            if (! $this->settlements->reverse((int) $id, $request->get('reason'), auth('admin')->id())) {
                throw new \DomainException('seller_settlement_not_reversible');
            }
            ToastMagic::success(translate('seller_settlement_reversed'));
        } catch (\Throwable $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    private function settlementCount(string $status, ?int $sellerId = null): int
    {
        return SellerSettlement::query()
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->where('status', $status)
            ->count();
    }
}
