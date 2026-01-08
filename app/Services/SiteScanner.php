<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class SiteScanner
{
    public function __construct(
        protected ConfigManager $configManager,
        protected DatabaseService $databaseService
    ) {}

    /**
     * Scan all directories in configured paths.
     * Returns ALL directories as projects, with has_public_folder flag.
     */
    public function scan(): array
    {
        $projects = [];
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
                    $hasPublicFolder = File::isDirectory($customPath.'/public');
                    $phpVersion = $this->detectPhpVersion($customPath, $name, $defaultPhp);

                    $project = [
                        'name' => $name,
                        'display_name' => $this->getDisplayName($customPath, $name),
                        'github_repo' => $this->getGitHubRepo($customPath),
                        'path' => $customPath,
                        'has_public_folder' => $hasPublicFolder,
                        'php_version' => $phpVersion,
                        'has_custom_php' => $phpVersion !== $defaultPhp,
                    ];

                    // Only add site info if has public folder
                    if ($hasPublicFolder) {
                        $project['domain'] = "{$name}.{$tld}";
                        $project['site_url'] = "https://{$name}.{$tld}";
                        $project['secure'] = true;
                    }

                    $projects[] = $project;
                }
            }
        }

        // Then scan configured paths for auto-discovered projects (ALL directories)
        foreach ($paths as $path) {
            $expandedPath = $this->expandPath($path);

            if (! File::isDirectory($expandedPath)) {
                continue;
            }

            $directories = File::directories($expandedPath);

            foreach ($directories as $directory) {
                $name = basename((string) $directory);

                // Skip if we've already seen this name (custom sites take precedence)
                if (isset($seenNames[$name])) {
                    continue;
                }

                $seenNames[$name] = true;

                $hasPublicFolder = File::isDirectory($directory.'/public');
                $phpVersion = $this->detectPhpVersion($directory, $name, $defaultPhp);

                $project = [
                    'name' => $name,
                    'display_name' => $this->getDisplayName($directory, $name),
                    'github_repo' => $this->getGitHubRepo($directory),
                    'path' => $directory,
                    'has_public_folder' => $hasPublicFolder,
                    'php_version' => $phpVersion,
                    'has_custom_php' => $phpVersion !== $defaultPhp,
                ];

                // Only add site info if has public folder
                if ($hasPublicFolder) {
                    $project['domain'] = "{$name}.{$tld}";
                    $project['site_url'] = "https://{$name}.{$tld}";
                    $project['secure'] = true;
                }

                $projects[] = $project;
            }
        }

        usort($projects, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $projects;
    }

    /**
     * Get only sites (projects with public folder) for Caddyfile generation.
     */
    public function scanSites(): array
    {
        return array_filter($this->scan(), fn ($p) => $p['has_public_folder']);
    }

    protected function detectPhpVersion(string $directory, string $name, string $default): string
    {
        // Check database first (primary source of truth)
        $dbVersion = $this->databaseService->getPhpVersion($name);
        if ($dbVersion !== null && $this->isValidPhpVersion($dbVersion)) {
            return $dbVersion;
        }

        // Fallback: check .php-version file (legacy support)
        $phpVersionFile = $directory.'/.php-version';
        if (File::exists($phpVersionFile)) {
            $version = trim(File::get($phpVersionFile));
            if ($this->isValidPhpVersion($version)) {
                // Migrate to database
                $this->databaseService->setProjectPhpVersion($name, $directory, $version);

                return $version;
            }
        }

        // Check config override (legacy, will be migrated)
        $configVersion = $this->configManager->getSitePhpVersion($name);
        if ($configVersion !== null) {
            return $configVersion;
        }

        return $default;
    }

    protected function isValidPhpVersion(string $version): bool
    {
        return in_array($version, ['8.3', '8.4', '8.5']);
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
        $projects = $this->scan();

        foreach ($projects as $project) {
            if ($project['name'] === $name) {
                return $project;
            }
        }

        return null;
    }

    public function findProject(string $name): ?array
    {
        return $this->findSite($name);
    }

    /**
     * Get display name for a project from .env APP_NAME or generate from slug.
     */
    protected function getDisplayName(string $directory, string $slug): string
    {
        // Try to read APP_NAME from .env file
        $envPath = $directory.'/.env';
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
            if (preg_match('/^APP_NAME=(.+)$/m', $envContent, $matches)) {
                $appName = trim($matches[1], "\"' ");
                if ($appName) {
                    return $appName;
                }
            }
        }

        // Generate display name from slug: "my-cool-project" -> "My Cool Project"
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Get GitHub repo URL from .git/config if available.
     */
    protected function getGitHubRepo(string $directory): ?string
    {
        $gitConfig = $directory.'/.git/config';
        if (! File::exists($gitConfig)) {
            return null;
        }

        $configContent = File::get($gitConfig);
        // Match git@github.com:owner/repo.git or https://github.com/owner/repo.git
        if (preg_match("/url\s*=\s*(?:git@github\.com:|https:\/\/github\.com\/)([^\/]+\/[^\/\s]+?)(?:\.git)?$/m", $configContent, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
