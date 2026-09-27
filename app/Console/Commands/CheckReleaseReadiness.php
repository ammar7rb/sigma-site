<?php

namespace App\Console\Commands;

use App\Services\ReleaseReadinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class CheckReleaseReadiness extends Command
{
    protected $signature = 'release:check
        {--strict : Treat production deployment requirements as blocking}
        {--workspace= : Parent directory containing Vendor app and user-app}
        {--report= : Write the JSON result to this path}';

    protected $description = 'Run non-destructive release readiness checks and emit an auditable JSON report';

    public function handle(ReleaseReadinessService $service): int
    {
        try {
            $result = $service->inspect((bool) $this->option('strict'), $this->option('workspace') ?: null);
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->line($json);
            if ($path = $this->option('report')) {
                File::ensureDirectoryExists(dirname((string) $path));
                File::put((string) $path, $json . PHP_EOL);
                $this->info('Report written to: ' . $path);
            }
            return $result['ready'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
