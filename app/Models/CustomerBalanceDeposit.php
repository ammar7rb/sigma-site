<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBalanceDeposit extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'float', 'submitted_amount' => 'float', 'method_information' => 'array', 'reviewed_at' => 'datetime'];
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
}
