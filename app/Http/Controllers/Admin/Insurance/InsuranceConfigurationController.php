<?php

namespace App\Http\Controllers\Admin\Insurance;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\CustomerInsuranceLedgerEntry;
use App\Models\InsuranceIncentive;
use App\Models\InsuranceIncentiveRedemption;
use App\Models\InsuranceRule;
use App\Models\InsuranceSubjectOverride;
use App\Models\InsuranceBalanceAction;
use App\Models\Notification;
use App\Models\OrderInsurance;
use App\Models\SellerOrderInsurance;
use App\Models\SupportTicket;
use App\Models\SupportTicketConv;
use App\Models\Seller;
use App\Models\User;
use App\Services\CustomerInsuranceBalanceService;
use App\Services\InsuranceBalanceGovernanceService;
use App\Services\SellerLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InsuranceConfigurationController extends Controller
{
    public function __construct(
        private readonly CustomerInsuranceBalanceService $customerBalances,
        private readonly SellerLedgerService $sellerLedger,
        private readonly InsuranceBalanceGovernanceService $governance,
    ) {}

    public function index(Request $request): View
    {
        $activeTab = in_array($request->get('tab'), ['rules', 'customers', 'sellers'], true)
            ? $request->get('tab') : 'rules';
        $customerInsurances = null;
        $sellerInsurances = null;
        if ($activeTab === 'customers') {
            $customerInsurances = $this->customerInsuranceQuery($request)->paginate(25)->withQueryString();
        }
        if ($activeTab === 'sellers') {
            $sellerInsurances = $this->sellerInsuranceQuery($request)->paginate(25)->withQueryString();
        }

        return view('admin-views.insurance.index', [
            'activeTab' => $activeTab,
            'customerRules' => InsuranceRule::query()->where('subject_type', 'customer')->orderBy('priority')->get(),
            'sellerRules' => InsuranceRule::query()->where('subject_type', 'seller')->orderBy('priority')->get(),
            'incentives' => InsuranceIncentive::query()->orderBy('priority')->get(),
            'subjectOverrides' => InsuranceSubjectOverride::query()->latest('id')->limit(100)->get(),
            'balanceActions' => InsuranceBalanceAction::query()->latest('id')->limit(50)->get(),
            'customerInsurances' => $customerInsurances,
            'sellerInsurances' => $sellerInsurances,
            'insuranceStats' => $this->insuranceStats(),
            'report' => [
                'welcome_credit' => (float) CustomerInsuranceLedgerEntry::query()->where('entry_type', 'welcome_credit')->sum('credit'),
                'premium_discount' => (float) OrderInsurance::query()->sum('discount_amount'),
                'welcome_redemptions' => InsuranceIncentiveRedemption::query()->where('redemption_type', 'welcome_credit')->count(),
                'discount_redemptions' => InsuranceIncentiveRedemption::query()->where('redemption_type', 'premium_discount')->count(),
            ],
        ]);
    }

    public function customerInsuranceDetails(OrderInsurance $insurance): View
    {
        $insurance->load(['customer', 'order']);
        $summary = $insurance->customer_id ? $this->customerBalances->summary((int) $insurance->customer_id) : ['available_balance' => 0];

        return view('admin-views.insurance.details', [
            'insurance' => $insurance,
            'partyType' => 'customer',
            'party' => $insurance->customer,
            'availableBalance' => (float) ($summary['available_balance'] ?? 0),
            'heldBalance' => $insurance->customer_id ? $this->governance->heldBalance('customer', (int) $insurance->customer_id) : 0,
            'actions' => InsuranceBalanceAction::query()->where('subject_type', 'customer')->where('subject_id', $insurance->customer_id)->latest('id')->limit(50)->get(),
        ]);
    }

    public function sellerInsuranceDetails(SellerOrderInsurance $insurance): View
    {
        $insurance->load(['seller', 'order', 'decisions']);
        $summary = $insurance->seller_id ? $this->sellerLedger->summary((int) $insurance->seller_id) : [];

        return view('admin-views.insurance.details', [
            'insurance' => $insurance,
            'partyType' => 'seller',
            'party' => $insurance->seller,
            'availableBalance' => (float) ($summary[SellerLedgerService::ORDER_INSURANCE_CREDIT] ?? 0),
            'heldBalance' => $insurance->seller_id ? $this->governance->heldBalance('seller', (int) $insurance->seller_id) : 0,
            'actions' => InsuranceBalanceAction::query()->where('subject_type', 'seller')->where('subject_id', $insurance->seller_id)->latest('id')->limit(50)->get(),
        ]);
    }

    public function notifyCustomer(Request $request, OrderInsurance $insurance): RedirectResponse
    {
        $data = $request->validate(['message' => 'required|string|max:4000']);
        $ticket = SupportTicket::query()->create([
            'customer_id' => $insurance->customer_id,
            'subject' => translate('Insurance_Administration_Notice').' #'.$insurance->order_id,
            'type' => 'account', 'purpose' => 'insurance_notice', 'priority' => 'medium',
            'description' => $data['message'], 'status' => 'open',
        ]);
        SupportTicketConv::query()->create([
            'support_ticket_id' => $ticket->id, 'admin_id' => auth('admin')->id(),
            'admin_message' => $data['message'], 'position' => 0,
        ]);

        return back()->with('success', translate('insurance_notice_sent'));
    }

    public function notifySeller(Request $request, SellerOrderInsurance $insurance): RedirectResponse
    {
        $data = $request->validate(['message' => 'required|string|max:4000']);
        Notification::query()->create([
            'sent_by' => 'admin', 'sent_to' => 'seller', 'seller_id' => $insurance->seller_id,
            'title' => translate('Insurance_Administration_Notice'), 'description' => $data['message'],
            'notification_count' => 1, 'status' => 1,
        ]);

        return back()->with('success', translate('insurance_notice_sent'));
    }

    private function customerInsuranceQuery(Request $request)
    {
        return OrderInsurance::query()->with(['customer', 'order'])->latest('id')
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->get('payment_status')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->get('search'));
                $numeric = preg_replace('/\D+/', '', $search);
                $query->where(function ($nested) use ($search, $numeric) {
                    if ($numeric !== '') $nested->orWhere('id', $numeric)->orWhere('order_id', $numeric)->orWhere('customer_id', $numeric);
                    $nested->orWhereHas('customer', fn ($customer) => $customer->where('f_name', 'like', "%{$search}%")->orWhere('l_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
                });
            });
    }

    private function sellerInsuranceQuery(Request $request)
    {
        return SellerOrderInsurance::query()->with(['seller', 'order'])->latest('id')
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->get('payment_status')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->get('search'));
                $numeric = preg_replace('/\D+/', '', $search);
                $query->where(function ($nested) use ($search, $numeric) {
                    if ($numeric !== '') $nested->orWhere('id', $numeric)->orWhere('order_id', $numeric)->orWhere('seller_id', $numeric);
                    $nested->orWhereHas('seller', fn ($seller) => $seller->where('f_name', 'like', "%{$search}%")->orWhere('l_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
                });
            });
    }

    private function insuranceStats(): array
    {
        return [
            'customer_total' => OrderInsurance::query()->count(),
            'customer_paid' => OrderInsurance::query()->where('payment_status', 'paid')->count(),
            'customer_paid_amount' => (float) OrderInsurance::query()->where('payment_status', 'paid')->sum('amount'),
            'customer_confiscated' => (float) OrderInsurance::query()->sum('confiscated_amount'),
            'seller_total' => SellerOrderInsurance::query()->count(),
            'seller_paid' => SellerOrderInsurance::query()->where('payment_status', 'paid')->count(),
            'seller_paid_amount' => (float) SellerOrderInsurance::query()->where('payment_status', 'paid')->sum('amount'),
            'seller_confiscated' => (float) SellerOrderInsurance::query()->sum('confiscated_amount'),
        ];
    }

    public function updateMaster(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_enabled' => 'nullable|in:1', 'customer_maturity_days' => 'required|integer|min:1|max:3650',
            'customer_payment_deadline_hours' => 'required|integer|min:1|max:720',
            'customer_purchase_refund_delay_days' => 'required|integer|min:0|max:365',
            'seller_enabled' => 'nullable|in:1', 'seller_pending_days' => 'required|integer|min:1|max:90',
            'seller_reuse_after_days' => 'required|integer|min:1|max:3650',
        ]);
        foreach ([
            'customer_order_insurance_status' => $request->boolean('customer_enabled') ? 1 : 0,
            'customer_order_insurance_maturity_days' => $data['customer_maturity_days'],
            'customer_order_insurance_payment_deadline_hours' => $data['customer_payment_deadline_hours'],
            'customer_purchase_refund_delay_days' => $data['customer_purchase_refund_delay_days'],
            'seller_order_insurance_status' => $request->boolean('seller_enabled') ? 1 : 0,
            'seller_order_insurance_pending_days' => $data['seller_pending_days'],
            'seller_order_insurance_reuse_after_days' => $data['seller_reuse_after_days'],
        ] as $type => $value) {
            BusinessSetting::query()->updateOrCreate(['type' => $type], ['value' => $value]);
        }
        return back()->with('success', translate('Updated_successfully'));
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $this->validateRule($request);
        if ($data['rule_type'] === 'default' && InsuranceRule::query()->where('subject_type', $data['subject_type'])->where('rule_type', 'default')->exists()) {
            throw ValidationException::withMessages(['rule_type' => translate('only_one_default_insurance_rule_is_allowed_per_subject')]);
        }
        $data['code'] = $data['code'] ?: Str::slug($data['subject_type'] . '-' . $data['name']);
        if (InsuranceRule::query()->where('subject_type', $data['subject_type'])->where('code', $data['code'])->exists()) {
            $data['code'] .= '-' . Str::lower(Str::random(5));
        }
        $data['is_active'] = $request->boolean('is_active');
        $data = $this->convertRuleCurrency($data);
        InsuranceRule::query()->create($data);
        return back()->with('success', translate('insurance_rule_saved'));
    }

    public function updateRule(Request $request, InsuranceRule $rule): RedirectResponse
    {
        $data = $this->validateRule($request, $rule);
        if ($data['rule_type'] === 'default' && InsuranceRule::query()->where('subject_type', $data['subject_type'])
            ->where('rule_type', 'default')->where('id', '!=', $rule->id)->exists()) {
            throw ValidationException::withMessages(['rule_type' => translate('only_one_default_insurance_rule_is_allowed_per_subject')]);
        }
        $data['code'] = $data['code'] ?: $rule->code;
        $data['is_active'] = $request->boolean('is_active');
        $data = $this->convertRuleCurrency($data);
        $rule->update($data);
        return back()->with('success', translate('insurance_rule_saved'));
    }

    public function deleteRule(InsuranceRule $rule): RedirectResponse
    {
        if ($rule->rule_type === 'default') return back()->with('error', translate('default_insurance_rule_cannot_be_deleted'));
        $rule->delete();
        return back()->with('success', translate('Deleted_successfully'));
    }

    public function storeSubjectOverride(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['customer', 'seller'])],
            'subject_id' => 'required|integer|min:1',
            'mode' => ['required', Rule::in(['exempt', 'required'])],
            'calculation_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'calculation_value' => 'nullable|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);
        $subjectExists = $data['subject_type'] === 'customer'
            ? User::query()->whereKey($data['subject_id'])->exists()
            : Seller::query()->whereKey($data['subject_id'])->exists();
        if (! $subjectExists) {
            return back()->withErrors(['subject_id' => 'رقم الحساب غير موجود لنوع الحساب المختار.'])->withInput();
        }
        if ($data['mode'] === 'required' && (! isset($data['calculation_type']) || ! isset($data['calculation_value']))) {
            return back()->withErrors(['calculation_value' => 'أدخل نوع وقيمة التأمين الإلزامي للحساب.'])->withInput();
        }
        if (($data['calculation_type'] ?? null) === 'percentage' && (float) ($data['calculation_value'] ?? 0) > 100) {
            return back()->withErrors(['calculation_value' => 'لا يمكن أن تتجاوز النسبة 100%.'])->withInput();
        }
        if (($data['calculation_type'] ?? null) === 'fixed') {
            $data['calculation_value'] = currencyConverter(amount: $data['calculation_value']);
        }
        if ($data['mode'] === 'exempt') {
            $data['calculation_type'] = null;
            $data['calculation_value'] = null;
        }
        $data['is_active'] = true;
        $data['created_by_admin_id'] = auth('admin')->id();
        InsuranceSubjectOverride::query()->updateOrCreate(
            ['subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id']],
            $data,
        );
        return back()->with('success', 'تم حفظ استثناء التأمين للحساب، وسيُطبق على الطلبات الجديدة.');
    }

    public function deleteSubjectOverride(InsuranceSubjectOverride $override): RedirectResponse
    {
        $override->delete();
        return back()->with('success', 'تم حذف استثناء التأمين وسيعود الحساب إلى القواعد العامة.');
    }

    public function toggleRule(InsuranceRule $rule): RedirectResponse
    {
        if ($rule->rule_type === 'default') {
            return back()->with('error', translate('default_insurance_rule_is_managed_from_master_settings'));
        }
        $rule->update(['is_active' => ! $rule->is_active]);
        return back()->with('success', translate('Updated_successfully'));
    }

    public function storeIncentive(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:191', 'code' => 'nullable|string|max:100|unique:insurance_incentives,code',
            'incentive_type' => ['required', Rule::in(['welcome_credit', 'premium_discount'])],
            'value_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'value' => 'required|numeric|min:0.01', 'maximum_amount' => 'nullable|numeric|min:0',
            'priority' => 'required|integer|min:1|max:100000', 'new_accounts_only' => 'nullable|in:1',
            'minimum_account_age_days' => 'nullable|integer|min:0', 'maximum_account_age_days' => 'nullable|integer|min:0',
            'usage_limit_per_customer' => 'required|integer|min:1|max:1000',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at', 'is_active' => 'nullable|in:1',
        ]);
        if ($data['value_type'] === 'percentage' && (float) $data['value'] > 100) {
            return back()->withErrors(['value' => translate('percentage_cannot_exceed_100')])->withInput();
        }
        if ($data['incentive_type'] === 'welcome_credit' && $data['value_type'] !== 'fixed') {
            return back()->withErrors(['value_type' => translate('welcome_insurance_credit_must_be_a_fixed_amount')])->withInput();
        }
        $data['code'] = $data['code'] ?: Str::slug($data['incentive_type'] . '-' . $data['name'] . '-' . Str::random(5));
        $data['is_active'] = $request->boolean('is_active');
        $data['new_accounts_only'] = $request->boolean('new_accounts_only');
        if ($data['value_type'] === 'fixed') $data['value'] = currencyConverter(amount: $data['value']);
        if (! empty($data['maximum_amount'])) $data['maximum_amount'] = currencyConverter(amount: $data['maximum_amount']);
        InsuranceIncentive::query()->create($data);
        return back()->with('success', translate('insurance_incentive_saved'));
    }

    public function toggleIncentive(InsuranceIncentive $incentive): RedirectResponse
    {
        $incentive->update(['is_active' => ! $incentive->is_active]);
        return back()->with('success', translate('Updated_successfully'));
    }

    public function deleteIncentive(InsuranceIncentive $incentive): RedirectResponse
    {
        if (InsuranceIncentiveRedemption::query()->where('insurance_incentive_id', $incentive->id)->exists()) {
            $incentive->update(['is_active' => false]);
            return back()->with('success', translate('insurance_incentive_archived_to_keep_financial_history'));
        }
        $incentive->delete();
        return back()->with('success', translate('Deleted_successfully'));
    }

    private function validateRule(Request $request, ?InsuranceRule $rule = null): array
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['customer', 'seller'])],
            'name' => 'required|string|max:191',
            'code' => ['nullable', 'string', 'max:100', Rule::unique('insurance_rules', 'code')->where('subject_type', $request->input('subject_type'))->ignore($rule?->id)],
            'rule_type' => ['required', Rule::in(['default', 'tier', 'account_age'])],
            'priority' => 'required|integer|min:1|max:100000', 'is_active' => 'nullable|in:1',
            'minimum_order_amount' => 'required|numeric|min:0', 'maximum_order_amount' => 'nullable|numeric|gt:minimum_order_amount',
            'minimum_account_age_days' => 'nullable|integer|min:0', 'maximum_account_age_days' => 'nullable|integer|gte:minimum_account_age_days',
            'calculation_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'calculation_value' => 'required|numeric|min:0.01',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at',
        ]);
        if ($data['calculation_type'] === 'percentage' && (float) $data['calculation_value'] > 100) {
            throw ValidationException::withMessages([
                'calculation_value' => translate('percentage_cannot_exceed_100'),
            ]);
        }
        return $data;
    }

    private function convertRuleCurrency(array $data): array
    {
        $data['minimum_order_amount'] = currencyConverter(amount: $data['minimum_order_amount']);
        if (! empty($data['maximum_order_amount'])) $data['maximum_order_amount'] = currencyConverter(amount: $data['maximum_order_amount']);
        if ($data['calculation_type'] === 'fixed') $data['calculation_value'] = currencyConverter(amount: $data['calculation_value']);
        return $data;
    }
}
