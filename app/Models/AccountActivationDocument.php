<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountActivationDocument extends Model
{
    protected $fillable = [
        'account_activation_case_id', 'document_type', 'custom_name', 'document_number', 'original_name',
        'storage_disk', 'storage_path', 'mime_type', 'size_bytes', 'sha256', 'expires_at', 'is_required',
        'verification_status', 'uploaded_by_admin_id', 'verified_by_admin_id', 'verified_at', 'metadata',
    ];

    protected $casts = [
        'account_activation_case_id' => 'integer',
        'document_number' => 'encrypted',
        'size_bytes' => 'integer',
        'expires_at' => 'date',
        'is_required' => 'boolean',
        'uploaded_by_admin_id' => 'integer',
        'verified_by_admin_id' => 'integer',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function activationCase(): BelongsTo
    {
        return $this->belongsTo(AccountActivationCase::class, 'account_activation_case_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'uploaded_by_admin_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by_admin_id');
    }
}
