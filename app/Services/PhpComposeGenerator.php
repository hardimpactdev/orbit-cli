<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class PhpComposeGenerator
{
    protected ConfigManager $configManager;

    protected string $composePath;

    public function __construct(ConfigManager $configManager)
    {
        $this->configManager = $configManager;
        $this->composePath = $configManager->getConfigPath().'/php/docker-compose.yml';
    }

    public function generate(): void
    {
        $paths = $this->configManager->getPaths();
        $volumeMounts = $this->generateVolumeMounts($paths);

        $compose = "services:
  php-83:
    image: dunglas/frankenphp:php8.3
    container_name: launchpad-php-83
    ports:
      - \"8083:8080\"
    volumes:
{$volumeMounts}      - ./php.ini:/usr/local/etc/php/php.ini:ro
      - ./Caddyfile:/etc/frankenphp/Caddyfile:ro
    restart: unless-stopped
    networks:
      - launchpad

  php-84:
    image: dunglas/frankenphp:php8.4
    container_name: launchpad-php-84
    ports:
      - \"8084:8080\"
    volumes:
{$volumeMounts}      - ./php.ini:/usr/local/etc/php/php.ini:ro
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
            $containerPath = '/app/'.basename($path);
            $mounts .= "      - {$expandedPath}:{$containerPath}\n";
        }

        return $mounts;
    }

    protected function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'].substr($path, 1);
        }

        return $path;
    }
}
