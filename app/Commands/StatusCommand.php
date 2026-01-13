<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\CaddyManager;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use App\Services\ServiceManager;
use App\Services\SiteScanner;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'status {--json : Output as JSON}';

    protected $description = 'Show Launchpad status and running services';

    public function handle(
        ServiceManager $serviceManager,
        DockerManager $dockerManager,
        ConfigManager $configManager,
        SiteScanner $siteScanner,
        PhpManager $phpManager,
        CaddyManager $caddyManager,
        HorizonManager $horizonManager
    ): int {
        // Detect architecture
        $isUsingFpm = $this->isUsingFpm($phpManager);
        $architecture = $isUsingFpm ? 'php-fpm' : 'frankenphp';

        $services = [];
        $runningCount = 0;
        $healthyCount = 0;

        if ($isUsingFpm) {
            // PHP-FPM Architecture - check host services

            // Check PHP-FPM pools
            $phpRunning = false;
            $phpVersions = [];
            foreach (['8.3', '8.4'] as $version) {
                if ($phpManager->isInstalled($version)) {
                    $running = $phpManager->isRunning($version);
                    $phpVersions[$version] = $running;
                    if ($running) {
                        $phpRunning = true;
                    }
                }
            }

            foreach ($phpVersions as $version => $running) {
                $normalized = str_replace('.', '', $version);
                $services["php-{$normalized}"] = [
                    'status' => $running ? 'running' : 'stopped',
                    'health' => $running ? 'healthy' : null,
                    'container' => null,
                    'type' => 'php-fpm',
                ];
                if ($running) {
                    $runningCount++;
                    $healthyCount++;
                }
            }

            // Check host Caddy
            $caddyRunning = $caddyManager->isRunning();
            $services['caddy'] = [
                'status' => $caddyRunning ? 'running' : 'stopped',
                'health' => $caddyRunning ? 'healthy' : null,
                'container' => null,
                'type' => 'host',
            ];
            if ($caddyRunning) {
                $runningCount++;
                $healthyCount++;
            }

            // Check Horizon service
            $horizonRunning = $horizonManager->isRunning();
            $services['horizon'] = [
                'status' => $horizonRunning ? 'running' : 'stopped',
                'health' => $horizonRunning ? 'healthy' : null,
                'container' => null,
                'type' => 'systemd',
            ];
            if ($horizonRunning) {
                $runningCount++;
                $healthyCount++;
            }
        }

        // Get Docker service statuses from ServiceManager
        $enabledServices = $serviceManager->getEnabled();
        $allStatuses = $dockerManager->getAllStatuses();

        foreach ($enabledServices as $name => $config) {
            $status = $allStatuses[$name] ?? ['running' => false, 'health' => null, 'container' => "launchpad-{$name}"];
            $services[$name] = [
                'status' => $status['running'] ? 'running' : 'stopped',
                'health' => $status['health'],
                'container' => $status['container'],
                'type' => 'docker',
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

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'running' => $isRunning,
                'architecture' => $architecture,
                'services' => $services,
                'services_running' => $runningCount,
                'services_healthy' => $healthyCount,
                'services_total' => count($services),
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
            $this->info("  Launchpad is running ({$runningCount}/".count($services).' services)');
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
            default => '<fg=green>●</>',
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
        $versions = ['8.3', '8.4'];
        foreach ($versions as $version) {
            if (file_exists($phpManager->getSocketPath($version))) {
                return true;
            }
        }

        return false;
    }
}
