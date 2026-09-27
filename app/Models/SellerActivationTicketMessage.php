<?php

namespace App\Models;

use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerActivationTicketMessage extends Model
{
    use StorageTrait;

    public const SENDER_SELLER = 'seller';
    public const SENDER_ADMIN = 'admin';
    public const SENDER_SYSTEM = 'system';

    protected $fillable = [
        'seller_activation_ticket_id', 'sender_type', 'sender_admin_id',
        'body', 'attachments', 'is_automatic',
    ];

    protected $casts = [
        'seller_activation_ticket_id' => 'integer',
        'sender_admin_id' => 'integer',
        'attachments' => 'array',
        'is_automatic' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SellerActivationTicket::class, 'seller_activation_ticket_id');
    }

    public function getAttachmentFullUrlAttribute(): array
    {
        return collect($this->attachments ?? [])->map(function ($attachment) {
            $item = is_array($attachment) ? $attachment : ['file_name' => $attachment];
            $linked = $this->storageLink(
                'seller-activation-ticket',
                (string) ($item['file_name'] ?? ''),
                (string) ($item['storage'] ?? 'public')
            );

            return [
                'url' => (string) ($linked['path'] ?? ''),
                'name' => (string) ($item['original_name'] ?? $item['file_name'] ?? ''),
                'mime_type' => (string) ($item['mime_type'] ?? ''),
            ];
        })->filter(fn (array $item) => $item['url'] !== '')->values()->all();
    }

    protected $appends = ['attachment_full_url'];
}
