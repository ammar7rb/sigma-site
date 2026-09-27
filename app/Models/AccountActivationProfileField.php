<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountActivationProfileField extends Model
{
    protected $fillable = [
        'account_activation_case_id', 'field_key', 'label', 'value', 'is_sensitive', 'is_required',
        'is_verified', 'created_by_admin_id', 'verified_by_admin_id', 'verified_at', 'metadata',
    ];

    protected $casts = [
        'account_activation_case_id' => 'integer',
        'value' => 'encrypted',
        'is_sensitive' => 'boolean',
        'is_required' => 'boolean',
        'is_verified' => 'boolean',
        'created_by_admin_id' => 'integer',
        'verified_by_admin_id' => 'integer',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function activationCase(): BelongsTo
    {
        return $this->belongsTo(AccountActivationCase::class, 'account_activation_case_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by_admin_id');
    }
}
