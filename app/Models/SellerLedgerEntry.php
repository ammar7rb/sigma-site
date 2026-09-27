<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SellerLedgerEntry extends Model
{
    protected $table = 'seller_ledger_entries';

    protected $fillable = [
        'seller_id',
        'bucket',
        'direction',
        'amount',
        'event_type',
        'reporting_category',
        'group_key',
        'idempotency_key',
        'reference_type',
        'reference_id',
        'reference_code',
        'metadata',
        'previous_hash',
        'entry_hash',
        'created_by',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'reference_id' => 'integer',
        'created_by' => 'integer',
        'amount' => 'decimal:20',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Seller ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Seller ledger entries are immutable.'));
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
