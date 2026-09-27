<?php

namespace App\Console\Commands;

use App\Models\SellerLedgerEntry;
use App\Services\SellerLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillSellerFinancialReferences extends Command
{
    protected $signature = 'seller-ledger:backfill-reporting-references';
    protected $description = 'Backfill reporting categories and stable references without altering ledger amounts';

    public function handle(SellerLedgerService $ledger): int
    {
        if (! Schema::hasColumn('seller_ledger_entries', 'reference_code')) return self::SUCCESS;
        $count = 0;
        SellerLedgerEntry::query()->where(function ($q) {
            $q->whereNull('reference_code')->orWhereNull('reporting_category');
        })->orderBy('id')->chunkById(200, function ($entries) use ($ledger, &$count): void {
            foreach ($entries as $entry) {
                DB::table('seller_ledger_entries')->where('id', $entry->id)->update([
                    'reporting_category' => $entry->reporting_category ?: $ledger->reportingCategory($entry->event_type),
                    'reference_code' => $entry->reference_code ?: $ledger->referenceCode($entry->group_key, $entry->reference_type, $entry->reference_id, $entry->metadata ?: []),
                ]);
                $count++;
            }
        });
        $this->info("Backfilled seller ledger references: {$count}");
        return self::SUCCESS;
    }
}
