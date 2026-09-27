<?php

namespace App\Services;

use App\Models\Seller;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SellerLedgerService
{
    public const SALES_TOTAL = 'sales_total';
    public const PENDING = 'pending';
    public const AVAILABLE = 'available';
    public const OPERATING = 'operating';
    public const ORDER_INSURANCE_CREDIT = 'order_insurance_credit';
    public const PENDING_WITHDRAW = 'pending_withdraw';
    public const WITHDRAWN = 'withdrawn';

    private const CREDIT = 'credit';
    private const DEBIT = 'debit';

    private static int $walletSyncSuppressed = 0;

    /** Run a legacy wallet flow without mistaking a held earning for available cash. */
    public function withoutWalletSync(callable $callback): mixed
    {
        self::$walletSyncSuppressed++;
        try {
            return $callback();
        } finally {
            self::$walletSyncSuppressed = max(0, self::$walletSyncSuppressed - 1);
        }
    }

    /**
     * Append one or more immutable movements as one financial event.
     * Each line receives a deterministic idempotency key, so webhook/retry calls
     * cannot duplicate money.
     */
    public function post(
        int $sellerId,
        string $eventType,
        string $groupKey,
        array $movements,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        ?int $createdBy = null,
    ): Collection {
        $this->ensureLedgerAvailable();
        if ($sellerId <= 0 || trim($eventType) === '' || trim($groupKey) === '') {
            throw new InvalidArgumentException('Seller ledger event identity is required.');
        }
        if (!in_array($eventType, ['legacy_opening_balance', 'legacy_wallet_sync'], true) && !SellerLedgerEntry::where('seller_id', $sellerId)->exists()) {
            $this->ensureLegacyOpeningBalance($sellerId);
        }

        return DB::transaction(function () use ($sellerId, $eventType, $groupKey, $movements, $referenceType, $referenceId, $metadata, $createdBy) {
            $entries = collect();

            foreach ($movements as $movement) {
                $bucket = (string) ($movement['bucket'] ?? '');
                $direction = (string) ($movement['direction'] ?? '');
                $amount = (float) ($movement['amount'] ?? 0);
                $this->assertMovement($bucket, $direction, $amount);

                $lineKey = hash('sha256', implode('|', [$groupKey, $bucket, $direction]));
                $existing = SellerLedgerEntry::query()->where('idempotency_key', $lineKey)->first();
                if ($existing) {
                    $entries->push($existing);
                    continue;
                }

                $previousHash = SellerLedgerEntry::query()
                    ->where('seller_id', $sellerId)
                    ->latest('id')
                    ->lockForUpdate()
                    ->value('entry_hash');
                $entryUuid = (string) Str::uuid();
                $reportingCategory = (string) ($movement['reporting_category'] ?? $this->reportingCategory($eventType));
                $referenceCode = (string) ($movement['reference_code'] ?? $this->referenceCode($groupKey, $referenceType, $referenceId, $metadata));
                $hashPayload = implode('|', [
                    $previousHash ?? '',
                    $entryUuid,
                    $sellerId,
                    $bucket,
                    $direction,
                    number_format($amount, 20, '.', ''),
                    $eventType,
                    $groupKey,
                ]);

                $payload = [
                    'seller_id' => $sellerId,
                    'bucket' => $bucket,
                    'direction' => $direction,
                    'amount' => number_format($amount, 20, '.', ''),
                    'event_type' => $eventType,
                    'reporting_category' => $reportingCategory,
                    'group_key' => $groupKey,
                    'idempotency_key' => $lineKey,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reference_code' => $referenceCode,
                    'metadata' => $metadata ?: null,
                    'previous_hash' => $previousHash,
                    'entry_hash' => hash('sha256', $hashPayload),
                    'created_by' => $createdBy,
                ];
                $columns = array_flip(Schema::getColumnListing('seller_ledger_entries'));
                $entries->push(SellerLedgerEntry::create(array_intersect_key($payload, $columns)));
            }

            return $entries;
        });
    }

    public function summary(int|Seller $seller): array
    {
        $sellerId = $seller instanceof Seller ? (int) $seller->id : (int) $seller;
        if ($sellerId <= 0 || !Schema::hasTable('seller_ledger_entries')) {
            return $this->legacySummary($sellerId);
        }

        $this->ensureLegacyOpeningBalance($sellerId);
        $summary = array_fill_keys($this->buckets(), 0.0);
        $rows = SellerLedgerEntry::query()
            ->select('bucket')
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS balance")
            ->where('seller_id', $sellerId)
            ->groupBy('bucket')
            ->pluck('balance', 'bucket');

        foreach ($summary as $bucket => $value) {
            $summary[$bucket] = round((float) ($rows[$bucket] ?? 0), 12);
        }

        return $summary;
    }

    public function assertSufficient(int $sellerId, string $bucket, float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Ledger debit amount must be positive.');
        }
        $summary = $this->summary($sellerId);
        if (($summary[$bucket] ?? 0) + 0.000000000001 < $amount) {
            throw new InvalidArgumentException('Insufficient seller ledger balance.');
        }
    }

    /**
     * Serializes balance-changing operations for one seller.
     *
     * Call this from inside the caller's database transaction before reading a
     * balance and posting its corresponding debit.  Locking the seller row is
     * deliberately independent from the legacy wallet row: every seller has
     * this row, while historical wallets may be created lazily.
     */
    public function lockSellerFinancialState(int $sellerId): void
    {
        if ($sellerId <= 0) {
            throw new InvalidArgumentException('Seller id is required to lock financial state.');
        }

        Seller::query()->lockForUpdate()->findOrFail($sellerId);
    }

    public function initializeFromLegacy(int $sellerId): void
    {
        $this->ensureLegacyOpeningBalance($sellerId);
    }

    /** Capture legacy direct wallet saves while old order flows are being migrated. */
    public function syncWalletMutation(SellerWallet $wallet): void
    {
        if (self::$walletSyncSuppressed > 0 || !Schema::hasTable('seller_ledger_entries') || ! $wallet->seller_id) {
            return;
        }

        $original = $wallet->getOriginal();
        $this->ensureLegacyOpeningBalance($wallet->seller_id, [
            'total_earning' => (float) ($original['total_earning'] ?? 0),
            'pending_withdraw' => (float) ($original['pending_withdraw'] ?? 0),
            'withdrawn' => (float) ($original['withdrawn'] ?? 0),
        ]);

        $movements = [];
        $totalDelta = round((float) $wallet->total_earning - (float) ($original['total_earning'] ?? 0), 12);
        if (abs($totalDelta) > 0.000000000001) {
            if ($totalDelta > 0) {
                $movements[] = ['bucket' => self::SALES_TOTAL, 'direction' => self::CREDIT, 'amount' => $totalDelta];
                $movements[] = ['bucket' => self::AVAILABLE, 'direction' => self::CREDIT, 'amount' => $totalDelta];
            } else {
                $movements[] = ['bucket' => self::AVAILABLE, 'direction' => self::DEBIT, 'amount' => abs($totalDelta)];
            }
        }

        foreach ([
            'pending_withdraw' => self::PENDING_WITHDRAW,
            'withdrawn' => self::WITHDRAWN,
        ] as $column => $bucket) {
            $delta = round((float) $wallet->{$column} - (float) ($original[$column] ?? 0), 12);
            if (abs($delta) > 0.000000000001) {
                $movements[] = [
                    'bucket' => $bucket,
                    'direction' => $delta > 0 ? self::CREDIT : self::DEBIT,
                    'amount' => abs($delta),
                ];
            }
        }

        if ($movements) {
            $stateKey = sha1(json_encode([
                $wallet->id,
                $wallet->total_earning,
                $wallet->pending_withdraw,
                $wallet->withdrawn,
            ], JSON_THROW_ON_ERROR));
            $this->post(
                sellerId: (int) $wallet->seller_id,
                eventType: 'legacy_wallet_sync',
                groupKey: 'legacy-wallet:' . $wallet->id . ':' . $stateKey,
                movements: $movements,
                referenceType: SellerWallet::class,
                referenceId: (int) $wallet->id,
            );
        }
    }

    private function ensureLegacyOpeningBalance(int $sellerId, ?array $values = null): void
    {
        if (!Schema::hasTable('seller_ledger_entries') || SellerLedgerEntry::where('seller_id', $sellerId)->exists()) {
            return;
        }

        $wallet = SellerWallet::where('seller_id', $sellerId)->first();
        $values ??= [
            'total_earning' => (float) ($wallet?->total_earning ?? 0),
            'pending_withdraw' => (float) ($wallet?->pending_withdraw ?? 0),
            'withdrawn' => (float) ($wallet?->withdrawn ?? 0),
        ];
        $total = max(0, (float) ($values['total_earning'] ?? 0));
        $movements = [];
        if ($total > 0) {
            $movements[] = ['bucket' => self::SALES_TOTAL, 'direction' => self::CREDIT, 'amount' => $total];
            $movements[] = ['bucket' => self::AVAILABLE, 'direction' => self::CREDIT, 'amount' => $total];
        }
        if (($values['pending_withdraw'] ?? 0) > 0) {
            $movements[] = ['bucket' => self::PENDING_WITHDRAW, 'direction' => self::CREDIT, 'amount' => (float) $values['pending_withdraw']];
        }
        if (($values['withdrawn'] ?? 0) > 0) {
            $movements[] = ['bucket' => self::WITHDRAWN, 'direction' => self::CREDIT, 'amount' => (float) $values['withdrawn']];
        }

        if ($movements) {
            $this->post(
                sellerId: $sellerId,
                eventType: 'legacy_opening_balance',
                groupKey: 'legacy-opening:' . $sellerId,
                movements: $movements,
                referenceType: SellerWallet::class,
                referenceId: (int) ($wallet?->id ?? 0) ?: null,
                metadata: ['source' => 'seller_wallets'],
            );
        }
    }

    private function legacySummary(int $sellerId): array
    {
        $wallet = SellerWallet::where('seller_id', $sellerId)->first();
        return [
            self::SALES_TOTAL => (float) ($wallet?->total_earning ?? 0),
            self::PENDING => 0.0,
            self::AVAILABLE => (float) ($wallet?->total_earning ?? 0),
            self::OPERATING => 0.0,
            self::PENDING_WITHDRAW => (float) ($wallet?->pending_withdraw ?? 0),
            self::WITHDRAWN => (float) ($wallet?->withdrawn ?? 0),
        ];
    }

    private function assertMovement(string $bucket, string $direction, float $amount): void
    {
        if (!in_array($bucket, $this->buckets(), true) || !in_array($direction, [self::CREDIT, self::DEBIT], true) || $amount <= 0) {
            throw new InvalidArgumentException('Invalid seller ledger movement.');
        }
    }

    private function buckets(): array
    {
        return [self::SALES_TOTAL, self::PENDING, self::AVAILABLE, self::OPERATING, self::ORDER_INSURANCE_CREDIT, self::PENDING_WITHDRAW, self::WITHDRAWN];
    }

    private function ensureLedgerAvailable(): void
    {
        if (!Schema::hasTable('seller_ledger_entries')) {
            throw new InvalidArgumentException('Seller ledger migrations have not been run.');
        }
    }

    public function reportingCategory(string $eventType): string
    {
        $event = strtolower($eventType);
        return match (true) {
            str_contains($event, 'insurance') => 'insurance',
            str_contains($event, 'settlement'), str_contains($event, 'shipping') => 'shipping',
            str_contains($event, 'package'), str_contains($event, 'promotion') => 'package',
            str_contains($event, 'withdraw') => 'withdrawal',
            str_contains($event, 'deposit'), str_contains($event, 'operating') => 'operating',
            str_contains($event, 'order'), str_contains($event, 'earning') => 'sales',
            default => 'balance',
        };
    }

    public function referenceCode(string $groupKey, ?string $referenceType, ?int $referenceId, array $metadata = []): string
    {
        $orderId = (int) ($metadata['order_id'] ?? 0);
        if ($orderId > 0) return 'ORD-'.$orderId;
        if ($referenceType && $referenceId) return strtoupper(class_basename($referenceType)).'-'.$referenceId;
        return 'LED-'.substr(hash('sha256', $groupKey), 0, 16);
    }
}
