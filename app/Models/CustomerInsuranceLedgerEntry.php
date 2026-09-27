<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CustomerInsuranceLedgerEntry extends Model
{
    protected $fillable = [
        'customer_id', 'order_insurance_id', 'order_id', 'entry_type',
        'credit', 'debit', 'reference', 'metadata',
    ];

    protected $casts = [
        'credit' => 'float',
        'debit' => 'float',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Customer insurance ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Customer insurance ledger entries are immutable.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function insurance(): BelongsTo
    {
        return $this->belongsTo(OrderInsurance::class, 'order_insurance_id');
    }
}
