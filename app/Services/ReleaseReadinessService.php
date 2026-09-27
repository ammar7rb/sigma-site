<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class ReleaseReadinessService
{
    public function inspect(bool $strict = false, ?string $workspace = null): array
    {
        $checks = [];
        $add = function (string $key, bool $passed, string $message, bool $deploymentOnly = false) use (&$checks, $strict): void {
            $checks[$key] = [
                'status' => $passed ? 'pass' : (($deploymentOnly && ! $strict) ? 'warning' : 'fail'),
                'message' => $message,
            ];
        };

        try {
            DB::connection()->getPdo();
            $add('database_connection', true, 'Database connection succeeded.');
        } catch (Throwable $exception) {
            $add('database_connection', false, 'Database connection failed: ' . $exception->getMessage());
        }

        $pending = $this->pendingMigrations();
        $add('pending_migrations', $pending === [], $pending === [] ? 'No pending migrations.' : 'Pending: ' . implode(', ', $pending));

        Artisan::call('schedule:list');
        $scheduleOutput = Artisan::output();
        $missingSchedules = array_values(array_filter(
            config('release.critical_schedules', []),
            fn (string $command): bool => ! str_contains($scheduleOutput, $command),
        ));
        $add('critical_schedules', $missingSchedules === [], $missingSchedules === [] ? 'All critical schedules are registered.' : 'Missing: ' . implode(', ', $missingSchedules));

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());
        $forbidden = collect(config('release.forbidden_route_fragments', []))->filter(
            fn (string $fragment): bool => $uris->contains(fn (string $uri) => str_contains($uri, $fragment)),
        )->values()->all();
        $add('legacy_customer_package_routes', $forbidden === [], $forbidden === [] ? 'Legacy customer package routes are absent.' : 'Forbidden route fragments: ' . implode(', ', $forbidden));

        $missingHooks = array_values(array_filter(
            config('release.payment_hooks', []),
            fn (string $hook): bool => ! function_exists($hook),
        ));
        $add('payment_webhook_hooks', $missingHooks === [], $missingHooks === [] ? 'Critical payment success/failure hooks are callable.' : 'Missing payment hooks: ' . implode(', ', $missingHooks));

        $missingSchema = [];
        foreach (config('release.required_schema', []) as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missingSchema[] = $table . '.*';
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) $missingSchema[] = $table . '.' . $column;
            }
        }
        $add('required_schema', $missingSchema === [], $missingSchema === [] ? 'Required release schema is present.' : 'Missing: ' . implode(', ', $missingSchema));

        $dataAudit = app(ReleaseDataPreparationService::class)->audit();
        $actionableData = collect($dataAudit)->except('customer_package_history_retained')->filter(fn (int $count): bool => $count > 0)->all();
        $add('legacy_data_actions', $actionableData === [], $actionableData === [] ? 'No legacy data action is pending.' : 'Pending legacy data actions: ' . json_encode($actionableData), true);

        $openRequirements = config('release.open_requirements', []);
        $add('open_requirements', $openRequirements === [], $openRequirements === [] ? 'No functional requirement is open.' : 'Open requirements: ' . implode(', ', array_keys($openRequirements)), true);

        $add('application_key', filled(config('app.key')), filled(config('app.key')) ? 'Application key is configured.' : 'APP_KEY is missing.', true);
        $add('debug_disabled', ! config('app.debug'), ! config('app.debug') ? 'Debug mode is disabled.' : 'APP_DEBUG must be false for release.', true);
        $add('https_url', str_starts_with((string) config('app.url'), 'https://'), str_starts_with((string) config('app.url'), 'https://') ? 'APP_URL uses HTTPS.' : 'APP_URL must use HTTPS for production.', true);
        $add('async_queue', config('queue.default') !== 'sync', config('queue.default') !== 'sync' ? 'Queue uses an asynchronous driver.' : 'QUEUE_CONNECTION=sync is not suitable for production.', true);
        $add('storage_writable', is_writable(storage_path()) && is_writable(storage_path('logs')), 'Storage and logs must be writable.');
        $add('backup_reference', filled(config('release.backup_reference')), filled(config('release.backup_reference')) ? 'Backup reference is recorded.' : 'Set RELEASE_BACKUP_REFERENCE after creating the deployment backup.', true);
        $add('release_approval', filled(config('release.approved_by')), filled(config('release.approved_by')) ? 'Release approver is recorded.' : 'Set RELEASE_APPROVED_BY before production rollout.', true);

        $workspace ??= (string) config('release.mobile_workspace');
        $mobile = $this->inspectMobileSources($workspace);
        foreach ($mobile as $key => $value) $checks[$key] = $value;

        $flutterAvailable = $this->flutterAvailable();
        $add('flutter_sdk', $flutterAvailable, $flutterAvailable ? 'Flutter SDK is available.' : 'Flutter SDK is not available; device/analyze tests must run in CI or a Flutter workstation.', true);

        $counts = collect($checks)->countBy('status')->all();
        return [
            'ready' => ($counts['fail'] ?? 0) === 0 && (! $strict || ($counts['warning'] ?? 0) === 0),
            'strict' => $strict,
            'summary' => [
                'passed' => $counts['pass'] ?? 0,
                'warnings' => $counts['warning'] ?? 0,
                'failed' => $counts['fail'] ?? 0,
            ],
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function pendingMigrations(): array
    {
        if (! Schema::hasTable('migrations')) return ['migrations_table_missing'];
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $ran = $migrator->getRepository()->getRan();
        return array_values(array_diff($files, $ran));
    }

    private function inspectMobileSources(string $workspace): array
    {
        $checks = [];
        foreach (['vendor' => 'Vendor app', 'customer' => 'user-app'] as $key => $folder) {
            $root = rtrim($workspace, '\\/') . DIRECTORY_SEPARATOR . $folder;
            $constants = $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'utill' . DIRECTORY_SEPARATOR . 'app_constants.dart';
            $test = $root . DIRECTORY_SEPARATOR . 'test' . DIRECTORY_SEPARATOR . 'widget_test.dart';
            $en = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . 'en.json';
            $ar = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . 'ar.json';
            $constantSource = is_file($constants) ? (string) file_get_contents($constants) : '';
            $sourceOk = str_contains($constantSource, 'String.fromEnvironment')
                && str_contains($constantSource, "'BASE_URL'")
                && is_file($test);
            $checks["{$key}_app_contract"] = ['status' => $sourceOk ? 'pass' : 'fail', 'message' => $sourceOk ? 'Environment URL and application tests are present.' : 'Missing environment URL or application test source.'];

            $translationOk = false;
            if (is_file($en) && is_file($ar)) {
                $enKeys = array_keys(json_decode((string) file_get_contents($en), true, 512, JSON_THROW_ON_ERROR));
                $arKeys = array_keys(json_decode((string) file_get_contents($ar), true, 512, JSON_THROW_ON_ERROR));
                sort($enKeys); sort($arKeys);
                $translationOk = $enKeys === $arKeys;
            }
            $checks["{$key}_translations"] = ['status' => $translationOk ? 'pass' : 'fail', 'message' => $translationOk ? 'Arabic and English translation keys match.' : 'Arabic and English translation keys differ or files are missing.'];
        }
        return $checks;
    }

    private function flutterAvailable(): bool
    {
        try {
            $process = new Process(['flutter', '--version']);
            $process->setTimeout(15)->run();
            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }
}
