<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ReleaseDataPreparationService
{
    public const SNAPSHOT_VERSION = 1;

    public function __construct(private readonly SubscriptionDurationService $durationService) {}

    public function audit(): array
    {
        return [
            'products_missing_required_dates' => $this->countProductsMissingDates(),
            'published_expired_products' => $this->countPublishedExpiredProducts(),
            'seller_subscriptions_missing_snapshot' => $this->countSellerSubscriptionsMissingSnapshot(),
            'customer_package_history_retained' => $this->countLegacyCustomerSubscriptions(),
            'customer_insurances_missing_maturity' => $this->countCustomerInsurancesMissingMaturity(),
            'seller_insurances_missing_reuse_date' => $this->countSellerInsurancesMissingReuseDate(),
            'seller_settlements_missing_snapshot' => $this->countSettlementsMissingSnapshot(),
        ];
    }

    public function apply(string $batchKey, ?int $adminId = null): array
    {
        $this->assertAuditTablesExist();
        if (! preg_match('/^[A-Za-z0-9._-]{3,100}$/', $batchKey)) {
            throw new RuntimeException('release_batch_key_invalid');
        }
        if (DB::table('release_data_migration_runs')->where('batch_key', $batchKey)->exists()) {
            throw new RuntimeException('release_batch_key_already_exists');
        }

        return DB::transaction(function () use ($batchKey, $adminId): array {
            $before = $this->audit();
            $runId = DB::table('release_data_migration_runs')->insertGetId([
                'batch_key' => $batchKey,
                'status' => 'running',
                'summary' => json_encode(['before' => $before], JSON_UNESCAPED_UNICODE),
                'started_at' => now(),
                'initiated_by_admin_id' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $changed = [
                'products_quarantined_missing_dates' => $this->quarantineProductsMissingDates($runId),
                'expired_products_unpublished' => $this->unpublishExpiredProducts($runId),
                'seller_subscription_snapshots_filled' => $this->backfillSellerSubscriptions($runId),
                'customer_insurance_maturity_filled' => $this->backfillCustomerInsuranceMaturity($runId),
                'seller_insurance_reuse_dates_filled' => $this->backfillSellerInsuranceReuseDates($runId),
                'seller_settlement_snapshots_filled' => $this->backfillSettlementSnapshots($runId),
            ];
            $after = $this->audit();
            $summary = compact('before', 'changed', 'after');

            DB::table('release_data_migration_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['batch_key' => $batchKey, 'run_id' => $runId] + $summary;
        });
    }

    public function rollback(string $batchKey): array
    {
        $this->assertAuditTablesExist();

        return DB::transaction(function () use ($batchKey): array {
            $run = DB::table('release_data_migration_runs')->where('batch_key', $batchKey)->lockForUpdate()->first();
            if (! $run) throw new RuntimeException('release_batch_not_found');
            if ($run->status === 'rolled_back') throw new RuntimeException('release_batch_already_rolled_back');
            if ($run->status !== 'completed') throw new RuntimeException('release_batch_not_completed');

            $restored = 0;
            DB::table('release_data_migration_items')
                ->where('release_data_migration_run_id', $run->id)
                ->where('status', 'applied')
                ->orderByDesc('id')
                ->get()
                ->each(function (object $item) use (&$restored): void {
                    if (! Schema::hasTable($item->table_name)) return;
                    $before = json_decode($item->before_values, true, 512, JSON_THROW_ON_ERROR);
                    $expectedAfter = json_decode($item->after_values, true, 512, JSON_THROW_ON_ERROR);
                    $current = (array) DB::table($item->table_name)->where('id', $item->record_id)->first(array_keys($expectedAfter));
                    if ($this->canonical($current) !== $this->canonical($expectedAfter)) {
                        throw new RuntimeException("release_rollback_conflict:{$item->table_name}:{$item->record_id}");
                    }
                    DB::table($item->table_name)->where('id', $item->record_id)->update($before);
                    DB::table('release_data_migration_items')->where('id', $item->id)->update([
                        'status' => 'rolled_back',
                        'updated_at' => now(),
                    ]);
                    $restored++;
                });

            DB::table('release_data_migration_runs')->where('id', $run->id)->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
                'updated_at' => now(),
            ]);

            return ['batch_key' => $batchKey, 'restored_records' => $restored, 'after_rollback' => $this->audit()];
        });
    }

    private function quarantineProductsMissingDates(int $runId): int
    {
        if (! $this->hasColumns('products', ['production_date', 'expiry_date', 'status'])) return 0;
        $count = 0;
        DB::table('products')->where('status', 1)
            ->where(fn ($query) => $query->whereNull('production_date')->orWhereNull('expiry_date'))
            ->orderBy('id')->chunkById(200, function ($products) use ($runId, &$count): void {
                foreach ($products as $product) {
                    $after = ['status' => 0];
                    if (Schema::hasColumn('products', 'featured')) $after['featured'] = 0;
                    if (Schema::hasColumn('products', 'seller_review_status') && ($product->added_by ?? null) === 'seller') {
                        $after['seller_review_status'] = 'suspended';
                        $after['seller_review_reason'] = 'legacy_product_dates_required';
                    }
                    $this->recordAndUpdate($runId, 'product_missing_dates_quarantine', 'products', (int) $product->id, $after);
                    $count++;
                }
            });
        return $count;
    }

    private function unpublishExpiredProducts(int $runId): int
    {
        if (! $this->hasColumns('products', ['expiry_date', 'status'])) return 0;
        $count = 0;
        DB::table('products')->where('status', 1)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', today())
            ->orderBy('id')->chunkById(200, function ($products) use ($runId, &$count): void {
                foreach ($products as $product) {
                    $after = ['status' => 0];
                    if (Schema::hasColumn('products', 'featured')) $after['featured'] = 0;
                    if (Schema::hasColumn('products', 'seller_review_status') && ($product->added_by ?? null) === 'seller') {
                        $after['seller_review_status'] = 'suspended';
                        $after['seller_review_reason'] = 'product_expired';
                    }
                    $this->recordAndUpdate($runId, 'expired_product_unpublish', 'products', (int) $product->id, $after);
                    $count++;
                }
            });
        return $count;
    }

    private function backfillSellerSubscriptions(int $runId): int
    {
        if (! $this->hasColumns('seller_package_subscriptions', ['duration_unit', 'duration_value', 'search_priority', 'cancellation_effect', 'metadata'])) return 0;
        $count = 0;
        DB::table('seller_package_subscriptions')->orderBy('id')->chunkById(200, function ($subscriptions) use ($runId, &$count): void {
            foreach ($subscriptions as $subscription) {
                $metadata = $this->jsonArray($subscription->metadata ?? null);
                if (($metadata['governance_snapshot_version'] ?? null) === self::SNAPSHOT_VERSION) continue;
                $package = $subscription->seller_package_id && Schema::hasTable('seller_packages')
                    ? DB::table('seller_packages')->find($subscription->seller_package_id)
                    : null;
                $duration = $this->durationService->normalize(
                    $subscription->duration_unit ?? $package?->duration_unit,
                    $subscription->duration_value ?? $package?->duration_value,
                    $subscription->package_validity_days ?? $package?->package_validity_days,
                );
                $metadata['governance_snapshot_version'] = self::SNAPSHOT_VERSION;
                $metadata['legacy_backfilled_at'] = now()->toIso8601String();
                $after = [
                    'duration_unit' => $duration['unit'],
                    'duration_value' => $duration['value'],
                    'search_priority' => (int) ($package?->search_priority ?? $subscription->search_priority ?? 100),
                    'cancellation_effect' => (string) ($package?->cancellation_effect ?? $subscription->cancellation_effect ?? 'end_of_period'),
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                ];
                $this->recordAndUpdate($runId, 'seller_subscription_snapshot', 'seller_package_subscriptions', (int) $subscription->id, $after);
                $count++;
            }
        });
        return $count;
    }

    private function backfillCustomerInsuranceMaturity(int $runId): int
    {
        if (! $this->hasColumns('order_insurances', ['payment_status', 'maturity_days', 'matures_at'])) return 0;
        $count = 0;
        DB::table('order_insurances')->where('payment_status', 'paid')->whereNull('matures_at')->orderBy('id')
            ->chunkById(200, function ($items) use ($runId, &$count): void {
                foreach ($items as $item) {
                    $days = max(1, (int) ($item->maturity_days ?: 90));
                    $metadata = $this->jsonArray($item->metadata ?? null);
                    $base = isset($metadata['paid_at']) ? Carbon::parse($metadata['paid_at']) : Carbon::parse($item->created_at ?? now());
                    $metadata['legacy_maturity_backfilled'] = true;
                    $this->recordAndUpdate($runId, 'customer_insurance_maturity', 'order_insurances', (int) $item->id, [
                        'maturity_days' => $days,
                        'matures_at' => $base->copy()->addDays($days),
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    ]);
                    $count++;
                }
            });
        return $count;
    }

    private function backfillSellerInsuranceReuseDates(int $runId): int
    {
        if (! $this->hasColumns('seller_order_insurances', ['payment_status', 'reuse_after_days', 'reusable_at'])) return 0;
        $count = 0;
        DB::table('seller_order_insurances')->where('payment_status', 'paid')->whereNull('reusable_at')->orderBy('id')
            ->chunkById(200, function ($items) use ($runId, &$count): void {
                foreach ($items as $item) {
                    $days = max(1, (int) ($item->reuse_after_days ?: 90));
                    $base = Carbon::parse($item->paid_at ?: $item->created_at ?: now());
                    $metadata = $this->jsonArray($item->metadata ?? null);
                    $metadata['legacy_reuse_backfilled'] = true;
                    $this->recordAndUpdate($runId, 'seller_insurance_reuse_date', 'seller_order_insurances', (int) $item->id, [
                        'reuse_after_days' => $days,
                        'reusable_at' => $base->copy()->addDays($days),
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    ]);
                    $count++;
                }
            });
        return $count;
    }

    private function backfillSettlementSnapshots(int $runId): int
    {
        if (! $this->hasColumns('seller_settlements', ['timezone_snapshot', 'pre_due_days_snapshot', 'metadata'])) return 0;
        $count = 0;
        DB::table('seller_settlements')->orderBy('id')->chunkById(200, function ($items) use ($runId, &$count): void {
            foreach ($items as $item) {
                $metadata = $this->jsonArray($item->metadata ?? null);
                if (($metadata['settlement_snapshot_version'] ?? null) === self::SNAPSHOT_VERSION && $item->timezone_snapshot) continue;
                $timezone = (string) ($item->timezone_snapshot ?: ($metadata['timezone'] ?? config('app.timezone', 'Africa/Cairo')));
                $preDue = max(0, min(30, (int) ($item->pre_due_days_snapshot ?? $metadata['pre_due_alert_days'] ?? 5)));
                $metadata['timezone'] = $timezone;
                $metadata['pre_due_alert_days'] = $preDue;
                $metadata['settlement_snapshot_version'] = self::SNAPSHOT_VERSION;
                $this->recordAndUpdate($runId, 'seller_settlement_snapshot', 'seller_settlements', (int) $item->id, [
                    'timezone_snapshot' => $timezone,
                    'pre_due_days_snapshot' => $preDue,
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                ]);
                $count++;
            }
        });
        return $count;
    }

    private function recordAndUpdate(int $runId, string $action, string $table, int $recordId, array $after): void
    {
        $record = DB::table($table)->where('id', $recordId)->first(array_keys($after));
        if (! $record) return;
        $before = (array) $record;
        DB::table('release_data_migration_items')->insert([
            'release_data_migration_run_id' => $runId,
            'action' => $action,
            'table_name' => $table,
            'record_id' => $recordId,
            'before_values' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_values' => json_encode($this->serializable($after), JSON_UNESCAPED_UNICODE),
            'status' => 'applied',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table($table)->where('id', $recordId)->update($after);
    }

    private function countProductsMissingDates(): int
    {
        if (! $this->hasColumns('products', ['production_date', 'expiry_date', 'status'])) return 0;
        return DB::table('products')->where('status', 1)->where(fn ($q) => $q->whereNull('production_date')->orWhereNull('expiry_date'))->count();
    }

    private function countPublishedExpiredProducts(): int
    {
        if (! $this->hasColumns('products', ['expiry_date', 'status'])) return 0;
        return DB::table('products')->where('status', 1)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', today())->count();
    }

    private function countSellerSubscriptionsMissingSnapshot(): int
    {
        if (! $this->hasColumns('seller_package_subscriptions', ['metadata'])) return 0;
        return DB::table('seller_package_subscriptions')->get(['metadata'])->filter(function (object $item): bool {
            return ($this->jsonArray($item->metadata ?? null)['governance_snapshot_version'] ?? null) !== self::SNAPSHOT_VERSION;
        })->count();
    }

    private function countLegacyCustomerSubscriptions(): int
    {
        return Schema::hasTable('customer_purchase_package_subscriptions') ? DB::table('customer_purchase_package_subscriptions')->count() : 0;
    }

    private function countCustomerInsurancesMissingMaturity(): int
    {
        if (! $this->hasColumns('order_insurances', ['payment_status', 'matures_at'])) return 0;
        return DB::table('order_insurances')->where('payment_status', 'paid')->whereNull('matures_at')->count();
    }

    private function countSellerInsurancesMissingReuseDate(): int
    {
        if (! $this->hasColumns('seller_order_insurances', ['payment_status', 'reusable_at'])) return 0;
        return DB::table('seller_order_insurances')->where('payment_status', 'paid')->whereNull('reusable_at')->count();
    }

    private function countSettlementsMissingSnapshot(): int
    {
        if (! $this->hasColumns('seller_settlements', ['timezone_snapshot', 'metadata'])) return 0;
        return DB::table('seller_settlements')->get(['timezone_snapshot', 'metadata'])->filter(function (object $item): bool {
            $metadata = $this->jsonArray($item->metadata ?? null);
            return ! $item->timezone_snapshot || ($metadata['settlement_snapshot_version'] ?? null) !== self::SNAPSHOT_VERSION;
        })->count();
    }

    private function hasColumns(string $table, array $columns): bool
    {
        return Schema::hasTable($table) && collect($columns)->every(fn (string $column) => Schema::hasColumn($table, $column));
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function serializable(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value, $values);
    }

    private function canonical(array $values): array
    {
        ksort($values);
        return array_map(function ($value) {
            if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i:s');
            if (is_bool($value)) return $value ? '1' : '0';
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) return (string) $value;
            return $value;
        }, $values);
    }

    private function assertAuditTablesExist(): void
    {
        if (! Schema::hasTable('release_data_migration_runs') || ! Schema::hasTable('release_data_migration_items')) {
            throw new RuntimeException('release_audit_tables_missing_run_migrations_first');
        }
    }
}
