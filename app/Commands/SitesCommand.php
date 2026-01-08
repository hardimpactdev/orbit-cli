<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\SiteScanner;
use LaravelZero\Framework\Commands\Command;

class SitesCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'sites {--json : Output as JSON}';

    protected $description = 'List all sites with their PHP versions';

    public function handle(SiteScanner $siteScanner, ConfigManager $configManager): int
    {
        $sites = $siteScanner->scanSites();
        $defaultPhp = $configManager->getDefaultPhpVersion();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'sites' => array_values(array_map(fn ($site) => [
                    'name' => $site['name'],
                    'display_name' => $site['display_name'] ?? ucwords(str_replace(['-', '_'], ' ', $site['name'])),
                    'github_repo' => $site['github_repo'] ?? null,
                    'project_type' => $site['project_type'] ?? 'unknown',
                    'domain' => $site['domain'],
                    'path' => $site['path'],
                    'php_version' => $site['php_version'],
                    'has_custom_php' => $site['has_custom_php'],
                    'secure' => true, // All sites use TLS via Caddy
                ], $sites)),
                'default_php_version' => $defaultPhp,
                'sites_count' => count($sites),
            ]);
        }

        if (empty($sites)) {
            $this->warn('No sites found. Add paths to your config.json file.');

            return self::SUCCESS;
        }

        $this->info('Sites:');
        $this->newLine();

        $tableData = [];
        foreach ($sites as $site) {
            $phpDisplay = $site['php_version'];
            if ($site['has_custom_php']) {
                $phpDisplay .= ' (custom)';
            } else {
                $phpDisplay .= ' (default)';
            }

            $tableData[] = [
                $site['domain'],
                $phpDisplay,
                $site['path'],
            ];
        }

        $this->table(['Domain', 'PHP Version', 'Path'], $tableData);

        $this->newLine();
        $this->line("Default PHP version: {$defaultPhp}");

        return self::SUCCESS;
    }
}
