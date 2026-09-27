<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyVersion extends Model
{
    protected $fillable = ['key', 'title', 'content', 'audience', 'version', 'is_required', 'is_active', 'effective_at', 'created_by_admin_id'];
    protected $casts = ['is_required' => 'boolean', 'is_active' => 'boolean', 'effective_at' => 'datetime'];
}
