<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class SiteScanner
{
    public function __construct(protected ConfigManager $configManager)
    {
    }

    public function scan(): array
    {
        $sites = [];
        $paths = $this->configManager->getPaths();
        $tld = $this->configManager->getTld();
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $siteOverrides = $this->configManager->getSiteOverrides();
        $seenNames = [];

        foreach ($paths as $path) {
            $expandedPath = $this->expandPath($path);

            if (! File::isDirectory($expandedPath)) {
                continue;
            }

            $directories = File::directories($expandedPath);

            foreach ($directories as $directory) {
                $name = basename((string) $directory);

                // First match wins - skip if we've already seen this name
                if (isset($seenNames[$name])) {
                    continue;
                }

                $seenNames[$name] = true;

                // Determine PHP version: .php-version file > config override > default
                $phpVersion = $this->detectPhpVersion($directory, $name, $siteOverrides, $defaultPhp);

                $sites[] = [
                    'name' => $name,
                    'domain' => "{$name}.{$tld}",
                    'path' => $directory,
                    'php_version' => $phpVersion,
                    'has_custom_php' => $phpVersion !== $defaultPhp,
                ];
            }
        }

        usort($sites, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $sites;
    }

    protected function detectPhpVersion(string $directory, string $name, array $overrides, string $default): string
    {
        // Check for .php-version file first
        $phpVersionFile = $directory.'/.php-version';
        if (File::exists($phpVersionFile)) {
            $version = trim(File::get($phpVersionFile));
            if ($this->isValidPhpVersion($version)) {
                return $version;
            }
        }

        return $overrides[$name]['php_version'] ?? $default;
    }

    protected function isValidPhpVersion(string $version): bool
    {
        return in_array($version, ['8.3', '8.4']);
    }

    protected function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'].substr($path, 1);
        }

        return $path;
    }

    public function findSite(string $name): ?array
    {
        $sites = $this->scan();

        foreach ($sites as $site) {
            if ($site['name'] === $name) {
                return $site;
            }
        }

        return null;
    }
}
