<?php

namespace App\Console\Commands;

use App\Models\SupportTicket;
use Illuminate\Console\Command;

class ArchiveClosedSupportTickets extends Command
{
    protected $signature = 'support-tickets:archive-closed';

    protected $description = 'Archive closed support tickets after the approved retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays(SupportTicket::ARCHIVE_AFTER_DAYS);
        $count = SupportTicket::query()
            ->where('status', 'close')
            ->whereNull('archived_at')
            ->where(function ($query) use ($cutoff): void {
                $query->where('closed_at', '<=', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('closed_at')->where('updated_at', '<=', $cutoff);
                    });
            })
            ->update(['archived_at' => now()]);

        $this->info("Archived {$count} closed support ticket(s).");

        return self::SUCCESS;
    }
}
