<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomepageLayoutSection extends Model
{
    protected $fillable = [
        'homepage_layout_version_id', 'section_key', 'is_visible', 'sort_order', 'source_mode',
        'automatic_source', 'product_ids', 'banner_ids', 'locale', 'starts_at', 'expires_at', 'settings',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'product_ids' => 'array',
        'banner_ids' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'settings' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(HomepageLayoutVersion::class, 'homepage_layout_version_id');
    }
}
