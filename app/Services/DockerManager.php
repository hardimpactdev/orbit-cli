<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class DockerManager
{
    protected string $basePath;

    protected ?string $lastError = null;

    public function __construct(protected ConfigManager $configManager)
    {
        $this->basePath = $this->configManager->getConfigPath();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function startAll(): void
    {
        $this->start('dns');
        $this->start('php');
        $this->start('caddy');

        foreach ($this->configManager->getEnabledServices() as $service) {
            $this->start($service);
        }
    }

    public function stopAll(): void
    {
        $this->stop('caddy');
        $this->stop('php');

        foreach ($this->configManager->getEnabledServices() as $service) {
            $this->stop($service);
        }

        $this->stop('dns');
    }

    public function start(string $service): bool
    {
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

    public function isRunning(string $container): bool
    {
        $result = Process::run("docker ps -q -f name={$container}");

        return ! empty(trim($result->output()));
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
}
