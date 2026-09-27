<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceSubjectOverride extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'mode', 'calculation_type', 'calculation_value',
        'reason', 'is_active', 'created_by_admin_id',
    ];

    protected $casts = [
        'subject_id' => 'integer', 'calculation_value' => 'float', 'is_active' => 'boolean',
        'created_by_admin_id' => 'integer',
    ];
}
