<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\PolicyAcceptance;
use App\Models\PolicyVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PolicyCenterController extends Controller
{
    public function index(): View
    {
        return view('admin-views.business-settings.policy-center.index', [
            'policies' => PolicyVersion::query()->latest('id')->paginate(25),
            'acceptances' => PolicyAcceptance::query()->with('policyVersion')->latest('accepted_at')->limit(50)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => 'required|string|max:80|alpha_dash',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'audience' => 'required|in:seller,customer,both',
            'effective_at' => 'nullable|date',
            'is_required' => 'nullable|boolean',
        ]);

        $version = (int) PolicyVersion::query()->where('key', $data['key'])->max('version') + 1;
        PolicyVersion::query()->create([
            ...$data,
            'version' => $version,
            'is_required' => $request->boolean('is_required'),
            'is_active' => true,
            'created_by_admin_id' => auth('admin')->id(),
        ]);

        return back()->with('success', translate('policy_version_created'));
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $policy = PolicyVersion::query()->findOrFail($id);
        $policy->update(['is_active' => $request->boolean('is_active')]);

        return back()->with('success', translate('status_updated_successfully'));
    }
}
