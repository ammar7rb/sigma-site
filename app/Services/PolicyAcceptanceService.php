<?php

namespace App\Services;

use App\Models\PolicyAcceptance;
use App\Models\PolicyVersion;
use Illuminate\Support\Facades\DB;

class PolicyAcceptanceService
{
    public function requiredFor(string $audience)
    {
        return PolicyVersion::query()->where('is_active', true)->where('is_required', true)
            ->whereIn('audience', [$audience, 'both'])
            ->where(fn ($q) => $q->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->unique('key')
            ->values();
    }

    public function accept(string $subjectType, int $subjectId, array $policyVersionIds): void
    {
        DB::transaction(function () use ($subjectType, $subjectId, $policyVersionIds) {
            foreach ($policyVersionIds as $policyVersionId) {
                PolicyAcceptance::query()->firstOrCreate([
                    'policy_version_id' => $policyVersionId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                ], ['accepted_at' => now()]);
            }
        });
    }
}
