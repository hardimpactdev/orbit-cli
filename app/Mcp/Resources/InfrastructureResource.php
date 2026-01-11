<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Services\DockerManager;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class InfrastructureResource extends Resource
{
    protected string $uri = 'launchpad://infrastructure';

    protected string $mimeType = 'application/json';

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
        'horizon' => 'launchpad-horizon',
    ];

    public function __construct(protected DockerManager $dockerManager)
    {
        //
    }

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
        return 'All running Docker services with their status, health, container names, and ports.';
    }

    public function handle(Request $request): Response
    {
        $services = [];
        $runningCount = 0;
        $healthyCount = 0;

        foreach ($this->containers as $name => $container) {
            $isRunning = $this->dockerManager->isRunning($container);
            $health = $isRunning ? $this->dockerManager->getHealthStatus($container) : null;

            $ports = $this->getContainerPorts($container);

            $services[$name] = [
                'container' => $container,
                'status' => $isRunning ? 'running' : 'stopped',
                'health' => $health,
                'ports' => $ports,
            ];

            if ($isRunning) {
                $runningCount++;
                if ($health === 'healthy' || $health === null) {
                    $healthyCount++;
                }
            }
        }

        return Response::json([
            'services' => $services,
            'summary' => [
                'total' => count($this->containers),
                'running' => $runningCount,
                'healthy' => $healthyCount,
                'stopped' => count($this->containers) - $runningCount,
            ],
        ]);
    }

    protected function getContainerPorts(string $container): array
    {
        $portsMap = [
            'launchpad-dns' => ['53/udp', '53/tcp'],
            'launchpad-php-83' => ['9000/tcp'],
            'launchpad-php-84' => ['9000/tcp'],
            'launchpad-php-85' => ['9000/tcp'],
            'launchpad-caddy' => ['80/tcp', '443/tcp'],
            'launchpad-postgres' => ['5432/tcp'],
            'launchpad-redis' => ['6379/tcp'],
            'launchpad-mailpit' => ['1025/tcp', '8025/tcp'],
            'launchpad-reverb' => ['6001/tcp', '6002/tcp'],
            'launchpad-horizon' => [],
        ];

        return $portsMap[$container] ?? [];
    }
}
