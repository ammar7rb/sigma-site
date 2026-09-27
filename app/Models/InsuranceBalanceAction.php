<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InsuranceBalanceAction extends Model
{
    public const SUBJECT_CUSTOMER = 'customer';
    public const SUBJECT_SELLER = 'seller';

    public const ACTION_HOLD = 'hold';
    public const ACTION_RELEASE = 'release';
    public const ACTION_CONFISCATE = 'confiscate';
    public const ACTION_REVERSE_CONFISCATION = 'reverse_confiscation';

    protected $fillable = [
        'subject_type', 'subject_id', 'action', 'amount', 'order_id',
        'order_insurance_id', 'seller_order_insurance_id', 'parent_action_id',
        'reference', 'reason', 'evidence', 'metadata', 'admin_id',
    ];

    protected $casts = [
        'subject_id' => 'integer', 'amount' => 'float', 'order_id' => 'integer',
        'order_insurance_id' => 'integer', 'seller_order_insurance_id' => 'integer',
        'parent_action_id' => 'integer', 'admin_id' => 'integer',
        'evidence' => 'array', 'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Insurance balance actions are immutable.'));
        static::deleting(fn () => throw new LogicException('Insurance balance actions are immutable.'));
    }
}
