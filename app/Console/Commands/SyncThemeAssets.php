<?php

namespace App\Console\Commands;

use App\Services\ThemeAssetPublisher;
use Illuminate\Console\Command;
use Throwable;

class SyncThemeAssets extends Command
{
    protected $signature = 'theme:sync-assets {theme? : Theme directory name; defaults to WEB_THEME}';

    protected $description = 'Synchronize theme public assets for installations served from the public directory';

    public function handle(ThemeAssetPublisher $publisher): int
    {
        $theme = (string) ($this->argument('theme') ?: theme_root_path());

        try {
            $publisher->sync($theme);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Theme assets are ready: {$theme}");

        return self::SUCCESS;
    }
}
