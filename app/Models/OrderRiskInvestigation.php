<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderRiskInvestigation extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'order_id', 'opened_by_admin_id', 'resolved_by_admin_id', 'status', 'risk_level',
        'reason', 'evidence', 'customer_insurance_frozen', 'seller_insurance_frozen',
        'previous_flow_status', 'resolution_action', 'resolution_note', 'opened_at', 'resolved_at',
    ];

    protected $casts = [
        'order_id' => 'integer', 'opened_by_admin_id' => 'integer', 'resolved_by_admin_id' => 'integer',
        'evidence' => 'array', 'customer_insurance_frozen' => 'boolean', 'seller_insurance_frozen' => 'boolean',
        'opened_at' => 'datetime', 'resolved_at' => 'datetime',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function messages(): HasMany { return $this->hasMany(OrderRiskInvestigationMessage::class, 'investigation_id'); }
}
