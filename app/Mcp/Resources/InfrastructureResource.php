<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\PhpManager;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class InfrastructureResource extends Resource
{
    protected string $uri = 'orbit://infrastructure';

    protected string $mimeType = 'application/json';

    public function __construct(
        protected DockerManager $dockerManager,
        protected ConfigManager $configManager,
        protected PhpManager $phpManager,
    ) {}

    public function name(): string
    {
        return 'infrastructure';
    }

    public function title(): string
    {
        return 'Infrastructure';
    }

    public function description(): string
    {
        return 'All running services with their status, health, and ports. Supports both PHP-FPM and FrankenPHP architectures.';
    }

    public function handle(Request $request): Response
    {
        $isUsingFpm = $this->isUsingFpm();
        $architecture = $isUsingFpm ? 'php-fpm' : 'frankenphp';

        $services = [];
        $runningCount = 0;
        $healthyCount = 0;

        // Core Docker containers (same for both architectures)
        $coreContainers = [
            'dns' => 'orbit-dns',
            'caddy' => 'orbit-caddy',
            'postgres' => 'orbit-postgres',
            'redis' => 'orbit-redis',
            'mailpit' => 'orbit-mailpit',
        ];

        foreach ($coreContainers as $name => $container) {
            $isRunning = $this->dockerManager->isRunning($container);
            $health = $isRunning ? $this->dockerManager->getHealthStatus($container) : null;
            $ports = $this->getContainerPorts($container);

            $services[$name] = [
                'container' => $container,
                'status' => $isRunning ? 'running' : 'stopped',
                'health' => $health,
                'ports' => $ports,
                'type' => 'docker',
            ];

            if ($isRunning) {
                $runningCount++;
                if ($health === 'healthy' || $health === null) {
                    $healthyCount++;
                }
            }
        }

        // PHP services - depends on architecture
        if ($isUsingFpm) {
            // PHP-FPM on host
            $phpVersions = ['8.3', '8.4', '8.5'];
            $phpServices = [];
            foreach ($phpVersions as $version) {
                $isRunning = $this->phpManager->isRunning($version);
                $socketPath = $this->phpManager->getSocketPath($version);
                $phpServices[$version] = [
                    'running' => $isRunning,
                    'socket' => $socketPath,
                    'socket_exists' => file_exists($socketPath),
                ];
                if ($isRunning) {
                    $runningCount++;
                    $healthyCount++;
                }
            }

            $services['php-fpm'] = [
                'type' => 'host-process',
                'status' => count(array_filter($phpServices, fn ($s) => $s['running'])) > 0 ? 'running' : 'stopped',
                'versions' => $phpServices,
            ];
        } else {
            // FrankenPHP Docker containers
            $phpContainers = [
                'php-83' => 'orbit-php-83',
                'php-84' => 'orbit-php-84',
                'php-85' => 'orbit-php-85',
            ];

            foreach ($phpContainers as $name => $container) {
                $isRunning = $this->dockerManager->isRunning($container);
                $health = $isRunning ? $this->dockerManager->getHealthStatus($container) : null;

                $services[$name] = [
                    'container' => $container,
                    'status' => $isRunning ? 'running' : 'stopped',
                    'health' => $health,
                    'ports' => ['9000/tcp'],
                    'type' => 'docker',
                ];

                if ($isRunning) {
                    $runningCount++;
                    if ($health === 'healthy' || $health === null) {
                        $healthyCount++;
                    }
                }
            }
        }

        // Optional services
        $optionalServices = ['reverb', 'horizon'];
        foreach ($optionalServices as $service) {
            if ($this->configManager->isServiceEnabled($service)) {
                $container = "orbit-{$service}";
                $isRunning = $this->dockerManager->isRunning($container);
                $health = $isRunning ? $this->dockerManager->getHealthStatus($container) : null;

                $services[$service] = [
                    'container' => $container,
                    'status' => $isRunning ? 'running' : 'stopped',
                    'health' => $health,
                    'ports' => $this->getContainerPorts($container),
                    'type' => 'docker',
                ];

                if ($isRunning) {
                    $runningCount++;
                    if ($health === 'healthy' || $health === null) {
                        $healthyCount++;
                    }
                }
            }
        }

        return Response::json([
            'architecture' => $architecture,
            'services' => $services,
            'summary' => [
                'total' => count($services),
                'running' => $runningCount,
                'healthy' => $healthyCount,
                'stopped' => count($services) - $runningCount,
            ],
        ]);
    }

    protected function getContainerPorts(string $container): array
    {
        $portsMap = [
            'orbit-dns' => ['53/udp', '53/tcp'],
            'orbit-caddy' => ['80/tcp', '443/tcp'],
            'orbit-postgres' => ['5432/tcp'],
            'orbit-redis' => ['6379/tcp'],
            'orbit-mailpit' => ['1025/tcp', '8025/tcp'],
            'orbit-reverb' => ['8080/tcp'],
            'orbit-horizon' => [],
        ];

        return $portsMap[$container] ?? [];
    }

    private function isUsingFpm(): bool
    {
        // Check if any FPM socket exists
        $versions = ['8.2', '8.3', '8.4', '8.5'];
        foreach ($versions as $version) {
            if (file_exists($this->phpManager->getSocketPath($version))) {
                return true;
            }
        }

        return false;
    }
}
