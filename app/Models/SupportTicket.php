<?php

namespace App\Models;

use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

/**
 * Class SupportTicket
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $subject
 * @property string|null $type
 * @property string $priority
 * @property string|null $description
 * @property array|null $attachment
 * @property string|null $reply
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */

class SupportTicket extends Model
{
    use StorageTrait;

    public const ARCHIVE_AFTER_DAYS = 180;

    protected $fillable = [
        'customer_id',
        'subject',
        'type',
        'purpose',
        'priority',
        'description',
        'reply',
        'status',
        'review_status',
        'review_note',
        'reviewed_by_admin_id',
        'reviewed_at',
        'closed_at',
        'archived_at',
        'created_at',
        'updated_at',
        'attachment',
        'purpose',
        'review_status',
        'reviewed_by_admin_id',
        'reviewed_at',
        'closed_at',
        'archived_at',
        'hidden_from_subject_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'customer_id' => 'integer',
        'priority' => 'string',
        'status' => 'string',
        'purpose' => 'string',
        'review_status' => 'string',
        'reviewed_by_admin_id' => 'integer',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'closed_at' => 'datetime',
        'archived_at' => 'datetime',
        'attachment' => 'array',
        'hidden_from_subject_at' => 'datetime',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(SupportTicketConv::class);
    }
    public function getAttachmentFullUrlAttribute():array|null
    {
        $images = [];
        $value = $this->attachment;
        if ($value){
            foreach ($value as $item){
                $item = isset($item['file_name']) ? (array)$item : ['file_name' => $item, 'storage' => 'public'];
                $images[] =  $this->storageLink('support-ticket',$item['file_name'],$item['storage'] ?? 'public');
            }
        }
        return $images;
    }
    protected $appends = ['attachment_full_url'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function scopeVisibleToSubject(Builder $query): Builder
    {
        return Schema::hasColumn($this->getTable(), 'hidden_from_subject_at')
            ? $query->whereNull('hidden_from_subject_at')
            : $query;
    }
}
