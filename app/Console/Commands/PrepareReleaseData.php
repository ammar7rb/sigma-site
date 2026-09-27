<?php

namespace App\Console\Commands;

use App\Services\ReleaseDataPreparationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class PrepareReleaseData extends Command
{
    protected $signature = 'release:prepare-data
        {--apply : Apply the audited changes and make them rollback-capable}
        {--rollback= : Roll back a previously applied batch key}
        {--batch= : Stable batch key used with --apply}
        {--admin-id= : Optional administrator identifier for the audit record}
        {--report= : Write the JSON result to this path}';

    protected $description = 'Audit, prepare, or safely roll back legacy data required by the release';

    public function handle(ReleaseDataPreparationService $service): int
    {
        try {
            if ($this->option('rollback')) {
                if ($this->option('apply')) {
                    $this->error('--apply and --rollback cannot be used together.');
                    return self::INVALID;
                }
                $result = ['mode' => 'rollback', 'result' => $service->rollback((string) $this->option('rollback'))];
            } elseif ($this->option('apply')) {
                $batch = (string) ($this->option('batch') ?: 'release-' . now()->format('Ymd-His'));
                $result = ['mode' => 'apply', 'result' => $service->apply($batch, $this->option('admin-id') ? (int) $this->option('admin-id') : null)];
            } else {
                $result = ['mode' => 'dry-run', 'result' => $service->audit()];
            }

            $result['generated_at'] = now()->toIso8601String();
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->line($json);
            if ($path = $this->option('report')) {
                File::ensureDirectoryExists(dirname((string) $path));
                File::put((string) $path, $json . PHP_EOL);
                $this->info('Report written to: ' . $path);
            }
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
