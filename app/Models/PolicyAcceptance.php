<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAcceptance extends Model
{
    protected $fillable = ['policy_version_id', 'subject_type', 'subject_id', 'accepted_at'];
    protected $casts = ['accepted_at' => 'datetime'];

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }
}
