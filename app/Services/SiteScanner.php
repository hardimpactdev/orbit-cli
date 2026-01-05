<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class SiteScanner
{
    public function __construct(protected ConfigManager $configManager) {}

    public function scan(): array
    {
        $sites = [];
        $paths = $this->configManager->getPaths();
        $tld = $this->configManager->getTld();
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $siteOverrides = $this->configManager->getSiteOverrides();
        $seenNames = [];

        // First, process custom sites with explicit paths defined in config
        foreach ($siteOverrides as $name => $override) {
            if (isset($override['path'])) {
                $customPath = $this->expandPath($override['path']);

                if (File::isDirectory($customPath)) {
                    $seenNames[$name] = true;
                    $phpVersion = $this->detectPhpVersion($customPath, $name, $siteOverrides, $defaultPhp);

                    $sites[] = [
                        'name' => $name,
                        'domain' => "{$name}.{$tld}",
                        'path' => $customPath,
                        'php_version' => $phpVersion,
                        'has_custom_php' => $phpVersion !== $defaultPhp,
                        'secure' => true,
                    ];
                }
            }
        }

        // Then scan configured paths for auto-discovered sites
        foreach ($paths as $path) {
            $expandedPath = $this->expandPath($path);

            if (! File::isDirectory($expandedPath)) {
                continue;
            }

            $directories = File::directories($expandedPath);

            foreach ($directories as $directory) {
                $name = basename((string) $directory);

                // Skip projects without a public folder (not web apps)
                if (! File::isDirectory($directory.'/public')) {
                    continue;
                }

                // Skip if we've already seen this name (custom sites take precedence)
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
                    'secure' => true,
                ];
            }
        }

        usort($sites, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

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
