<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CaddyfileGenerator
{
    protected string $caddyfilePath;

    protected string $phpCaddyfilePath;

    public function __construct(
        protected ConfigManager $configManager,
        protected SiteScanner $siteScanner,
        protected ?WorktreeService $worktreeService = null
    ) {
        $this->caddyfilePath = $this->configManager->getConfigPath().'/caddy/Caddyfile';
        $this->phpCaddyfilePath = $this->configManager->getConfigPath().'/php/Caddyfile';
    }

    public function generate(): void
    {
        $this->generateCaddyfile();
        $this->generatePhpCaddyfile();
    }

    protected function generateCaddyfile(): void
    {
        $sites = $this->siteScanner->scanSites();
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $defaultPhpContainer = $this->getContainerName($defaultPhp);

        $caddyfile = '{
    local_certs
}

';

        // Generate explicit entry for each site (wildcard certs don't work in browsers)
        foreach ($sites as $site) {
            $container = $site['has_custom_php']
                ? $this->getContainerName($site['php_version'])
                : $defaultPhpContainer;

            $caddyfile .= "{$site['domain']} {
    tls internal
    reverse_proxy {$container}:8080
}

";
        }

        // Generate entries for worktrees
        $worktrees = $this->getWorktreesForCaddy();
        foreach ($worktrees as $worktree) {
            $container = $this->getContainerName($worktree['php_version']);

            $caddyfile .= "{$worktree['domain']} {
    tls internal
    reverse_proxy {$container}:8080
}

";
        }

        // Add Reverb WebSocket service if enabled
        if ($this->configManager->isServiceEnabled('reverb')) {
            $tld = $this->configManager->get('tld') ?: 'test';
            $caddyfile .= "reverb.{$tld} {
    tls internal
    @websocket {
        path /app /app/*
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @websocket launchpad-reverb:6001
    reverse_proxy launchpad-reverb:6001
}

";
        }
        File::put($this->caddyfilePath, $caddyfile);
    }

    protected function generatePhpCaddyfile(): void
    {
        $sites = $this->siteScanner->scanSites();
        $paths = $this->configManager->getPaths();

        $caddyfile = '{
    frankenphp
    order php_server before file_server
    auto_https off
}

';

        foreach ($sites as $site) {
            $dockerPath = $this->getDockerPath($site['path'], $paths);
            $root = $this->getDocumentRoot($dockerPath);

            $caddyfile .= "http://{$site['domain']}:8080 {\n";
            $caddyfile .= "    root * {$root}\n";

            // Always include Vite proxy - works when Vite is running, fails gracefully when not
            $caddyfile .= "\n";
            $caddyfile .= "    @vite path /@vite/* /@id/* /@fs/* /resources/* /node_modules/* /lang/* /__devtools__/*\n";
            $caddyfile .= "    reverse_proxy @vite 172.18.0.1:5173\n";
            $caddyfile .= "\n";
            $caddyfile .= "    @ws {\n";
            $caddyfile .= "        header Connection *Upgrade*\n";
            $caddyfile .= "        header Upgrade websocket\n";
            $caddyfile .= "    }\n";
            $caddyfile .= "    reverse_proxy @ws 172.18.0.1:5173\n";
            $caddyfile .= "\n";

            $caddyfile .= "    php_server\n";
            $caddyfile .= "}\n\n";
        }

        // Generate entries for worktrees
        $worktrees = $this->getWorktreesForCaddy();
        foreach ($worktrees as $worktree) {
            // Worktrees are typically outside the configured paths, so we need
            // to mount them separately. For now, we'll use a direct path mapping.
            $dockerPath = $this->getWorktreeDockerPath($worktree['path']);
            $root = $this->getDocumentRoot($dockerPath);

            $caddyfile .= "http://{$worktree['domain']}:8080 {
    root * {$root}
    php_server
}

";
        }

        File::put($this->phpCaddyfilePath, $caddyfile);
    }

    protected function getDockerPath(string $hostPath, array $configPaths): string
    {
        // Convert host path to docker container path
        // ~/Projects/myapp -> /app/Projects/myapp
        foreach ($configPaths as $configPath) {
            $expandedConfigPath = $this->expandPath($configPath);
            if (str_starts_with($hostPath, $expandedConfigPath)) {
                $relativePath = substr($hostPath, strlen($expandedConfigPath));
                $baseName = basename((string) $configPath);

                return "/app/{$baseName}{$relativePath}";
            }
        }

        return $hostPath;
    }

    protected function getWorktreeDockerPath(string $hostPath): string
    {
        // Worktrees are mounted at /worktrees in the container
        // /var/tmp/vibe-kanban/worktrees/task-id/project -> /worktrees/task-id/project
        if (str_starts_with($hostPath, '/var/tmp/vibe-kanban/worktrees/')) {
            $relativePath = substr($hostPath, strlen('/var/tmp/vibe-kanban/worktrees/'));

            return "/worktrees/{$relativePath}";
        }

        // Fallback: try to use the path directly
        return $hostPath;
    }

    protected function getDocumentRoot(string $basePath): string
    {
        // Check if it's a Laravel app (has public directory)
        // We'll assume public exists if there's a public folder
        return "{$basePath}/public";
    }

    protected function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'].substr($path, 1);
        }

        return $path;
    }

    public function reload(): bool
    {
        $result = Process::run('docker exec launchpad-caddy caddy reload --config /etc/caddy/Caddyfile');

        return $result->successful();
    }

    public function reloadPhp(): bool
    {
        $result83 = Process::run('docker exec launchpad-php-83 caddy reload --config /etc/caddy/Caddyfile 2>/dev/null');
        $result84 = Process::run('docker exec launchpad-php-84 caddy reload --config /etc/caddy/Caddyfile 2>/dev/null');

        return $result83->successful() || $result84->successful();
    }

    protected function getContainerName(string $version): string
    {
        $versionNumber = str_replace('.', '', $version);

        return "launchpad-php-{$versionNumber}";
    }

    protected function getWorktreesForCaddy(): array
    {
        if ($this->worktreeService === null) {
            // Lazy load to avoid circular dependency
            $this->worktreeService = app(WorktreeService::class);
        }

        try {
            return $this->worktreeService->getLinkedWorktreesForCaddy();
        } catch (\Exception) {
            // If worktree service fails, return empty array
            return [];
        }
    }
}
