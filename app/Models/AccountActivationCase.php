<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountActivationCase extends Model
{
    public const SUBJECT_CUSTOMER = 'customer';
    public const SUBJECT_SELLER = 'seller';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_NEEDS_INFORMATION = 'needs_information';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'subject_type', 'subject_id', 'source_type', 'source_id', 'status',
        'assigned_admin_id', 'reviewed_by_admin_id', 'decision_note', 'assigned_at',
        'reviewed_at', 'subject_hidden_at', 'completed_at', 'metadata',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'source_id' => 'integer',
        'assigned_admin_id' => 'integer',
        'reviewed_by_admin_id' => 'integer',
        'assigned_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'subject_hidden_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(AccountActivationProfileField::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AccountActivationDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccountActivationCaseEvent::class)->orderByDesc('created_at');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'subject_id');
    }

    public function subjectModel(): User|Seller|null
    {
        return $this->subject_type === self::SUBJECT_SELLER ? $this->seller : $this->customer;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true);
    }
}
