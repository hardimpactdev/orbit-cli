<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\PhpManager;
use App\Services\SiteScanner;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'status {--json : Output as JSON}';

    protected $description = 'Show Launchpad status and running services';

    public function handle(
        DockerManager $dockerManager,
        ConfigManager $configManager,
        SiteScanner $siteScanner,
        PhpManager $phpManager
    ): int {
        // Single batched query for all container statuses
        $allStatuses = $dockerManager->getAllStatuses();

        $services = [];
        $runningCount = 0;
        $healthyCount = 0;

        foreach ($allStatuses as $name => $status) {
            $services[$name] = [
                'status' => $status['running'] ? 'running' : 'stopped',
                'health' => $status['health'],
                'container' => $status['container'],
            ];

            if ($status['running']) {
                $runningCount++;
                if ($status['health'] === 'healthy') {
                    $healthyCount++;
                }
            }
        }

        $sites = $siteScanner->scan();
        $isRunning = $runningCount > 0;

        // Detect architecture
        $isUsingFpm = $this->isUsingFpm($phpManager);
        $architecture = $isUsingFpm ? 'php-fpm' : 'frankenphp';

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'running' => $isRunning,
                'architecture' => $architecture,
                'services' => $services,
                'services_running' => $runningCount,
                'services_healthy' => $healthyCount,
                'services_total' => count($allStatuses),
                'sites_count' => count($sites),
                'config_path' => $configManager->getConfigPath(),
                'tld' => $configManager->getTld(),
                'default_php_version' => $configManager->getDefaultPhpVersion(),
                'cli_version' => config('app.version'),
                'cli_path' => $_SERVER['SCRIPT_FILENAME'] ?? null,
            ]);
        }

        // Human-readable output
        $this->newLine();

        if ($isRunning) {
            $this->info("  Launchpad is running ({$runningCount}/".count($allStatuses).' services)');
        } else {
            $this->warn('  Launchpad is stopped');
        }

        $this->newLine();
        $this->line('  <fg=cyan>Services:</>');

        foreach ($services as $name => $info) {
            $statusIcon = $this->getStatusIcon($info['status'], $info['health']);
            $healthLabel = $this->getHealthLabel($info['health']);
            $this->line("    {$statusIcon} {$name}{$healthLabel}");
        }

        $this->newLine();
        $this->line('  <fg=cyan>Architecture:</> '.$architecture);
        $this->line('  <fg=cyan>Sites:</> '.count($sites));
        $this->line('  <fg=cyan>Config:</> '.$configManager->getConfigPath());
        $this->line('  <fg=cyan>TLD:</> .'.$configManager->getTld());
        $this->line('  <fg=cyan>Default PHP:</> '.$configManager->getDefaultPhpVersion());
        $this->newLine();

        return self::SUCCESS;
    }

    protected function getStatusIcon(string $status, ?string $health): string
    {
        if ($status !== 'running') {
            return '<fg=red>○</>';
        }

        return match ($health) {
            'healthy' => '<fg=green>●</>',
            'unhealthy' => '<fg=red>●</>',
            'starting' => '<fg=yellow>●</>',
            default => '<fg=green>●</>', // Running but no healthcheck
        };
    }

    protected function getHealthLabel(?string $health): string
    {
        return match ($health) {
            'healthy' => ' <fg=green>(healthy)</>',
            'unhealthy' => ' <fg=red>(unhealthy)</>',
            'starting' => ' <fg=yellow>(starting)</>',
            default => '',
        };
    }

    private function isUsingFpm(PhpManager $phpManager): bool
    {
        // Check if any FPM socket exists
        $versions = ['8.2', '8.3', '8.4', '8.5'];
        foreach ($versions as $version) {
            if (file_exists($phpManager->getSocketPath($version))) {
                return true;
            }
        }

        return false;
    }
}
