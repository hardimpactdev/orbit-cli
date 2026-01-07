<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class PhpComposeGenerator
{
    protected string $composePath;

    public function __construct(protected ConfigManager $configManager)
    {
        $this->composePath = $this->configManager->getConfigPath().'/php/docker-compose.yml';
    }

    public function generate(): void
    {
        $paths = $this->configManager->getPaths();
        $volumeMounts = $this->generateVolumeMounts($paths);
        $worktreeMount = $this->generateWorktreeMount();
        $vibeKanbanMount = $this->generateVibeKanbanMount();

        $compose = "services:
  php-83:
    build:
      context: .
      dockerfile: Dockerfile.php83
    image: launchpad-php:8.3
    container_name: launchpad-php-83
    ports:
      - \"8083:8080\"
    volumes:
{$volumeMounts}{$worktreeMount}{$vibeKanbanMount}      - ./php.ini:/usr/local/etc/php/php.ini:ro
      - ./Caddyfile:/etc/frankenphp/Caddyfile:ro
    restart: unless-stopped
    networks:
      - launchpad

  php-84:
    build:
      context: .
      dockerfile: Dockerfile.php84
    image: launchpad-php:8.4
    container_name: launchpad-php-84
    ports:
      - \"8084:8080\"
    volumes:
{$volumeMounts}{$worktreeMount}{$vibeKanbanMount}      - ./php.ini:/usr/local/etc/php/php.ini:ro
      - ./Caddyfile:/etc/frankenphp/Caddyfile:ro
    restart: unless-stopped
    networks:
      - launchpad

networks:
  launchpad:
    external: true
";

        File::put($this->composePath, $compose);
    }

    protected function generateVolumeMounts(array $paths): string
    {
        $mounts = '';
        foreach ($paths as $path) {
            $expandedPath = $this->expandPath($path);
            $containerPath = '/app/'.basename((string) $path);
            $mounts .= "      - {$expandedPath}:{$containerPath}\n";
        }

        return $mounts;
    }

    protected function generateWorktreeMount(): string
    {
        // Mount the vibe-kanban worktrees directory if it exists
        $worktreesPath = '/var/tmp/vibe-kanban/worktrees';

        if (File::isDirectory($worktreesPath)) {
            return "      - {$worktreesPath}:/worktrees\n";
        }

        return '';
    }

    protected function generateVibeKanbanMount(): string
    {
        // Mount the vibe-kanban data directory for SQLite database access
        // Used by orchestrator to create/manage VibeKanban projects
        $vibeKanbanPath = $this->expandPath('~/.local/share/vibe-kanban');

        if (File::isDirectory($vibeKanbanPath)) {
            // Mount to same path so VibeKanbanClient code works unchanged
            return "      - {$vibeKanbanPath}:{$vibeKanbanPath}\n";
        }

        return '';
    }

    protected function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'].substr($path, 1);
        }

        return $path;
    }
}
