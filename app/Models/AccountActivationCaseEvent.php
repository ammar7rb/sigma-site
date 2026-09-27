<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountActivationCaseEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'account_activation_case_id', 'event_type', 'admin_id', 'from_status',
        'to_status', 'note', 'metadata', 'created_at',
    ];

    protected $casts = [
        'account_activation_case_id' => 'integer',
        'admin_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function activationCase(): BelongsTo
    {
        return $this->belongsTo(AccountActivationCase::class, 'account_activation_case_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
