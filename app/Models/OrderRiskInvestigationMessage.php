<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRiskInvestigationMessage extends Model
{
    protected $fillable = ['investigation_id', 'admin_id', 'recipient_type', 'recipient_id', 'message', 'support_ticket_id'];
    protected $casts = ['investigation_id' => 'integer', 'admin_id' => 'integer', 'recipient_id' => 'integer', 'support_ticket_id' => 'integer'];

    public function investigation(): BelongsTo { return $this->belongsTo(OrderRiskInvestigation::class, 'investigation_id'); }
}
