<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class DockerManager
{
    /**
     * All launchpad containers that can be managed.
     */
    public const CONTAINERS = [
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

    protected string $basePath;

    protected ?string $lastError = null;

    /**
     * Cached container statuses from batch query.
     *
     * @var array<string, array{running: bool, health: ?string}>|null
     */
    protected ?array $statusCache = null;

    public function __construct(protected ConfigManager $configManager)
    {
        $this->basePath = $this->configManager->getConfigPath();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get all container names.
     *
     * @return array<string, string>
     */
    public function getContainers(): array
    {
        return self::CONTAINERS;
    }

    /**
     * Get status and health for all containers in a single batch query.
     * This is much faster than calling isRunning() and getHealthStatus() individually.
     *
     * @return array<string, array{running: bool, health: ?string}>
     */
    public function getAllStatuses(): array
    {
        if ($this->statusCache !== null) {
            return $this->statusCache;
        }

        // Initialize all containers as stopped
        $statuses = [];
        foreach (self::CONTAINERS as $name => $container) {
            $statuses[$name] = [
                'running' => false,
                'health' => null,
                'container' => $container,
            ];
        }

        // Single query to get all running launchpad containers with their health status
        // Format: container_name|health_status (health_status is empty if no healthcheck)
        $result = Process::run(
            "docker ps --filter 'name=launchpad-' --format '{{.Names}}|{{if .Status}}{{.Status}}{{end}}' 2>/dev/null"
        );

        if (! $result->successful()) {
            return $statuses;
        }

        $runningContainers = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            if (empty($line)) {
                continue;
            }
            [$containerName] = explode('|', $line, 2);
            $runningContainers[] = $containerName;
        }

        // Mark running containers
        foreach (self::CONTAINERS as $name => $container) {
            if (in_array($container, $runningContainers, true)) {
                $statuses[$name]['running'] = true;
            }
        }

        // Batch health check for all running containers
        if (! empty($runningContainers)) {
            $containerList = implode(' ', $runningContainers);
            $healthResult = Process::run(
                "docker inspect --format '{{.Name}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' {$containerList} 2>/dev/null"
            );

            if ($healthResult->successful()) {
                foreach (explode("\n", trim($healthResult->output())) as $line) {
                    if (empty($line)) {
                        continue;
                    }
                    [$containerName, $health] = explode('|', $line, 2);
                    $containerName = ltrim($containerName, '/'); // docker inspect returns /container_name

                    // Find the service name for this container
                    foreach (self::CONTAINERS as $serviceName => $container) {
                        if ($container === $containerName) {
                            $statuses[$serviceName]['health'] = $health === 'none' ? null : $health;
                            break;
                        }
                    }
                }
            }
        }

        $this->statusCache = $statuses;

        return $statuses;
    }

    /**
     * Clear the status cache (call after starting/stopping containers).
     */
    public function clearStatusCache(): void
    {
        $this->statusCache = null;
    }

    public function startAll(): void
    {
        $this->clearStatusCache();
        $this->start('dns');
        $this->start('php');
        $this->start('caddy');

        foreach ($this->configManager->getEnabledServices() as $service) {
            $this->start($service);
        }
    }

    public function stopAll(): void
    {
        $this->clearStatusCache();
        $this->stop('caddy');
        $this->stop('php');

        foreach ($this->configManager->getEnabledServices() as $service) {
            $this->stop($service);
        }

        $this->stop('dns');
    }

    public function start(string $service): bool
    {
        $this->clearStatusCache();
        $composePath = $this->getComposePath($service);

        if (! file_exists($composePath)) {
            $this->lastError = "File not found: {$composePath}";

            return false;
        }

        $result = Process::run("docker compose -f {$composePath} up -d");

        if (! $result->successful()) {
            $this->lastError = $result->errorOutput() ?: $result->output();
        }

        return $result->successful();
    }

    public function stop(string $service): bool
    {
        $this->clearStatusCache();
        $composePath = $this->getComposePath($service);

        if (! file_exists($composePath)) {
            $this->lastError = "File not found: {$composePath}";

            return false;
        }

        $result = Process::run("docker compose -f {$composePath} down");

        if (! $result->successful()) {
            $this->lastError = $result->errorOutput() ?: $result->output();
        }

        return $result->successful();
    }

    public function restart(string $service): bool
    {
        $this->stop($service);

        return $this->start($service);
    }

    public function build(string $service): bool
    {
        $composePath = $this->getComposePath($service);

        if (! file_exists($composePath)) {
            $this->lastError = "File not found: {$composePath}";

            return false;
        }

        $env = $this->getServiceEnv($service);
        $result = Process::env($env)->run("docker compose -f {$composePath} build");

        if (! $result->successful()) {
            $this->lastError = $result->errorOutput() ?: $result->output();
        }

        return $result->successful();
    }

    public function pull(string $service): bool
    {
        $composePath = $this->getComposePath($service);

        if (! file_exists($composePath)) {
            $this->lastError = "File not found: {$composePath}";

            return false;
        }

        $result = Process::run("docker compose -f {$composePath} pull");

        if (! $result->successful()) {
            $this->lastError = $result->errorOutput() ?: $result->output();
        }

        return $result->successful();
    }

    public function logs(string $container, bool $follow = true): void
    {
        $followFlag = $follow ? '-f' : '';
        Process::forever()->tty()->run("docker logs {$followFlag} {$container}");
    }

    public function createNetwork(): bool
    {
        // Network is created by DNS docker-compose, no manual creation needed
        return true;
    }

    /**
     * Check if a container is running.
     * Prefer using getAllStatuses() for batch queries.
     */
    public function isRunning(string $container): bool
    {
        // Use cache if available
        if ($this->statusCache !== null) {
            foreach ($this->statusCache as $status) {
                if ($status['container'] === $container) {
                    return $status['running'];
                }
            }
        }

        $result = Process::run("docker ps -q -f name={$container}");

        return ! empty(trim($result->output()));
    }

    /**
     * Get the health status of a container.
     * Prefer using getAllStatuses() for batch queries.
     *
     * @return string|null 'healthy', 'unhealthy', 'starting', or null if no healthcheck
     */
    public function getHealthStatus(string $container): ?string
    {
        // Use cache if available
        if ($this->statusCache !== null) {
            foreach ($this->statusCache as $status) {
                if ($status['container'] === $container) {
                    return $status['health'];
                }
            }
        }

        $result = Process::run(
            "docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' {$container} 2>/dev/null"
        );

        if (! $result->successful()) {
            return null;
        }

        $status = trim($result->output());

        return $status === 'none' ? null : $status;
    }

    protected function getComposePath(string $service): string
    {
        return "{$this->basePath}/{$service}/docker-compose.yml";
    }

    protected function getServiceEnv(string $service): array
    {
        if ($service === 'dns') {
            return [
                'HOST_IP' => $this->configManager->getHostIp(),
                'TLD' => $this->configManager->getTld(),
            ];
        }

        return [];
    }

    /**
     * Check if a container exists (running or stopped).
     */
    public function containerExists(string $name): bool
    {
        $result = Process::run("docker ps -a --format '{{.Names}}' | grep -q '^{$name}$'");

        return $result->successful();
    }

    /**
     * Remove a container (stops it first if running).
     */
    public function removeContainer(string $name): bool
    {
        if (! $this->containerExists($name)) {
            return true;
        }

        // Stop if running
        Process::run("docker stop {$name} 2>/dev/null");

        // Remove container
        $result = Process::run("docker rm {$name}");

        return $result->successful();
    }
}
