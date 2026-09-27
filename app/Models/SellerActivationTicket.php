<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerActivationTicket extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_AWAITING_SELLER = 'awaiting_seller';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'seller_id', 'status', 'subject', 'assigned_admin_id', 'approved_by_admin_id',
        'decision_note', 'opened_at', 'approved_at', 'closed_at',
        'hidden_from_subject_at', 'completed_at',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'assigned_admin_id' => 'integer',
        'approved_by_admin_id' => 'integer',
        'opened_at' => 'datetime',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
        'hidden_from_subject_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SellerActivationTicketMessage::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_OPEN, self::STATUS_AWAITING_SELLER, self::STATUS_UNDER_REVIEW,
        ], true) && $this->hidden_from_subject_at === null;
    }
}
