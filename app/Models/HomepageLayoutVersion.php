<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomepageLayoutVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'theme', 'version', 'status', 'created_by_admin_id', 'published_by_admin_id', 'published_at', 'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(HomepageLayoutSection::class)->orderBy('sort_order')->orderBy('id');
    }
}
