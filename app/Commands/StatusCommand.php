<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\SiteScanner;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'status {--json : Output as JSON}';

    protected $description = 'Show Launchpad status and running services';

    protected array $containers = [
        'dns' => 'launchpad-dns',
        'php-83' => 'launchpad-php-83',
        'php-84' => 'launchpad-php-84',
        'php-85' => 'launchpad-php-85',
        'caddy' => 'launchpad-caddy',
        'postgres' => 'launchpad-postgres',
        'redis' => 'launchpad-redis',
        'mailpit' => 'launchpad-mailpit',
        'reverb' => 'launchpad-reverb',
    ];

    public function handle(
        DockerManager $dockerManager,
        ConfigManager $configManager,
        SiteScanner $siteScanner
    ): int {
        $services = [];
        $runningCount = 0;
        $healthyCount = 0;

        foreach ($this->containers as $name => $container) {
            $isRunning = $dockerManager->isRunning($container);
            $health = $isRunning ? $dockerManager->getHealthStatus($container) : null;

            $services[$name] = [
                'status' => $isRunning ? 'running' : 'stopped',
                'health' => $health,
                'container' => $container,
            ];

            if ($isRunning) {
                $runningCount++;
                if ($health === 'healthy') {
                    $healthyCount++;
                }
            }
        }

        $sites = $siteScanner->scan();
        $isRunning = $runningCount > 0;

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'running' => $isRunning,
                'services' => $services,
                'services_running' => $runningCount,
                'services_healthy' => $healthyCount,
                'services_total' => count($this->containers),
                'sites_count' => count($sites),
                'config_path' => $configManager->getConfigPath(),
                'tld' => $configManager->getTld(),
                'default_php_version' => $configManager->getDefaultPhpVersion(),
            ]);
        }

        // Human-readable output
        $this->newLine();

        if ($isRunning) {
            $this->info("  Launchpad is running ({$runningCount}/".count($this->containers).' services)');
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
}
