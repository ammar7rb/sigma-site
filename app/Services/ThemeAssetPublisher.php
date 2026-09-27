<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class ThemeAssetPublisher
{
    private string $themesRoot;

    private string $publicThemesRoot;

    public function __construct(?string $themesRoot = null, ?string $publicThemesRoot = null)
    {
        $this->themesRoot = $themesRoot ?? base_path('resources/themes');
        $this->publicThemesRoot = $publicThemesRoot ?? public_path('themes');
    }

    public function sync(string $theme): void
    {
        $this->assertValidThemeName($theme);

        if ($theme === 'default') {
            return;
        }

        $source = $this->themesRoot . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . 'public';
        $destination = $this->publicThemesRoot . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . 'public';

        if (!File::isDirectory($source)) {
            throw new RuntimeException("Theme public assets directory was not found: {$theme}");
        }

        // A Unix symbolic link or Windows junction may already expose resources/themes
        // through public/themes. Copying in that case would copy the directory onto itself.
        $sourceRealPath = realpath($source);
        $destinationRealPath = realpath($destination);
        if ($sourceRealPath !== false && $destinationRealPath !== false && $sourceRealPath === $destinationRealPath) {
            return;
        }

        File::ensureDirectoryExists(dirname($destination));

        if (!File::copyDirectory($source, $destination)) {
            throw new RuntimeException("Theme public assets could not be synchronized: {$theme}");
        }

        if (!$this->isReady($theme)) {
            throw new RuntimeException("Theme public assets are incomplete after synchronization: {$theme}");
        }
    }

    public function isReady(string $theme): bool
    {
        $this->assertValidThemeName($theme);

        if ($theme === 'default') {
            return true;
        }

        $themePublicPath = $this->publicThemesRoot . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . 'public';

        return File::isDirectory($themePublicPath . DIRECTORY_SEPARATOR . 'assets')
            && File::isFile($themePublicPath . DIRECTORY_SEPARATOR . 'addon' . DIRECTORY_SEPARATOR . 'theme_routes.php');
    }

    private function assertValidThemeName(string $theme): void
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $theme)) {
            throw new InvalidArgumentException('Invalid theme name.');
        }
    }
}
