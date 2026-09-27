<?php

namespace App\Http\Controllers\Admin\Insurance;

use App\Http\Controllers\Controller;
use App\Models\InsuranceBalanceAction;
use App\Models\OrderInsurance;
use App\Models\SellerOrderInsurance;
use App\Services\InsuranceBalanceGovernanceService;
use App\Services\CustomerPostPurchaseInsuranceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InsuranceBalanceGovernanceController extends Controller
{
    public function __construct(private readonly InsuranceBalanceGovernanceService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['customer', 'seller'])],
            'subject_id' => 'required|integer|min:1',
            'action' => ['required', Rule::in(['hold', 'confiscate'])],
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:2000',
            'order_id' => 'nullable|integer|min:1',
            'evidence_note' => 'nullable|string|max:2000',
            'request_token' => 'required|uuid',
        ]);
        $reference = 'INS-ACT-'.$data['request_token'];
        $context = [
            'order_id' => $data['order_id'] ?? null,
            'evidence' => array_filter(['note' => $data['evidence_note'] ?? null]),
        ];

        try {
            if ($data['action'] === 'hold') {
                $this->service->hold(
                    $data['subject_type'], (int) $data['subject_id'], (float) $data['amount'],
                    $reference, $data['reason'], auth('admin')->id(), $context,
                );
            } else {
                $this->service->confiscateAvailable(
                    $data['subject_type'], (int) $data['subject_id'], (float) $data['amount'],
                    $reference, $data['reason'], auth('admin')->id(), $context,
                );
            }
        } catch (DomainException $exception) {
            return back()->withInput()->with('error', translate($exception->getMessage()));
        }

        return back()->with('success', translate('insurance_balance_action_recorded'));
    }

    public function followUp(Request $request, InsuranceBalanceAction $balanceAction): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['release', 'confiscate', 'reverse_confiscation'])],
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:2000',
            'evidence_note' => 'nullable|string|max:2000',
            'request_token' => 'required|uuid',
        ]);
        $reference = 'INS-ACT-'.$data['request_token'];
        $context = ['evidence' => array_filter(['note' => $data['evidence_note'] ?? null])];

        try {
            match ($data['action']) {
                'release' => $this->service->releaseHold($balanceAction, (float) $data['amount'], $reference, $data['reason'], auth('admin')->id(), $context),
                'confiscate' => $this->service->confiscateHold($balanceAction, (float) $data['amount'], $reference, $data['reason'], auth('admin')->id(), $context),
                'reverse_confiscation' => $this->service->reverseConfiscation($balanceAction, (float) $data['amount'], $reference, $data['reason'], auth('admin')->id(), $context),
            };
        } catch (DomainException $exception) {
            return back()->withInput()->with('error', translate($exception->getMessage()));
        }

        return back()->with('success', translate('insurance_balance_action_recorded'));
    }

    public function confiscateCustomerInsurance(Request $request, OrderInsurance $insurance): RedirectResponse
    {
        $data = $this->validateInsuranceRecordAction($request);
        try {
            $this->service->confiscateCustomerInsurance(
                $insurance, (float) $data['amount'], 'INS-ACT-'.$data['request_token'],
                $data['reason'], auth('admin')->id(), ['evidence' => array_filter(['note' => $data['evidence_note'] ?? null])],
            );
        } catch (DomainException $exception) {
            return back()->withInput()->with('error', translate($exception->getMessage()));
        }

        return back()->with('success', translate('insurance_balance_action_recorded'));
    }

    public function confiscateSellerInsurance(Request $request, SellerOrderInsurance $insurance): RedirectResponse
    {
        $data = $this->validateInsuranceRecordAction($request);
        try {
            $this->service->confiscateSellerInsurance(
                $insurance, (float) $data['amount'], 'INS-ACT-'.$data['request_token'],
                $data['reason'], auth('admin')->id(), ['evidence' => array_filter(['note' => $data['evidence_note'] ?? null])],
            );
        } catch (DomainException $exception) {
            return back()->withInput()->with('error', translate($exception->getMessage()));
        }

        return back()->with('success', translate('insurance_balance_action_recorded'));
    }

    public function reviewCustomerOfflinePayment(
        Request $request,
        OrderInsurance $insurance,
        CustomerPostPurchaseInsuranceService $postPurchase,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => 'required|string|max:2000',
        ]);
        if ($insurance->payment_method !== 'offline_payment' || $insurance->payment_status !== 'unpaid' || $insurance->status !== 'pending_review') {
            return back()->with('error', translate('customer_insurance_offline_review_not_available'));
        }

        try {
            if ($data['decision'] === 'approve') {
                $postPurchase->markPaid(
                    $insurance,
                    'offline_payment',
                    'customer-insurance-offline-' . $insurance->id,
                    auth('admin')->id(),
                    $data['reason'],
                );
            } else {
                $postPurchase->rejectOfflinePayment($insurance, $data['reason'], auth('admin')->id());
            }
        } catch (DomainException $exception) {
            return back()->with('error', translate($exception->getMessage()));
        }

        return back()->with('success', translate($data['decision'] === 'approve'
            ? 'customer_insurance_offline_payment_approved'
            : 'customer_insurance_offline_payment_rejected'));
    }

    private function validateInsuranceRecordAction(Request $request): array
    {
        return $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:2000',
            'evidence_note' => 'nullable|string|max:2000',
            'request_token' => 'required|uuid',
        ]);
    }
}
