<?php

namespace App\Commands;

use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    protected $signature = 'init';

    protected $description = 'First-time setup: creates config directory, pulls images, sets up DNS';

    public function handle(
        ConfigManager $configManager,
        DockerManager $dockerManager,
        CaddyfileGenerator $caddyfileGenerator
    ): int {
        $this->info('Creating Launchpad...');

        // Check if Docker is running
        $dockerCheck = Process::run('docker info');
        if (! $dockerCheck->successful()) {
            $this->error('Docker is not running. Please start Docker/OrbStack first.');

            return self::FAILURE;
        }

        $configPath = $configManager->getConfigPath();

        // 1. Create directory structure
        $this->task('Creating directories', function () use ($configPath) {
            $directories = [
                $configPath,
                "{$configPath}/php",
                "{$configPath}/caddy",
                "{$configPath}/dns",
                "{$configPath}/postgres",
                "{$configPath}/postgres/data",
                "{$configPath}/redis",
                "{$configPath}/redis/data",
                "{$configPath}/mailpit",
            ];

            foreach ($directories as $dir) {
                File::ensureDirectoryExists($dir);
            }

            return true;
        });

        // 2. Copy stubs
        $this->task('Copying configuration files', function () use ($configPath) {
            $stubsPath = base_path('stubs');

            // Copy all stub files
            $this->copyStubDirectory("{$stubsPath}/php", "{$configPath}/php");
            $this->copyStubDirectory("{$stubsPath}/caddy", "{$configPath}/caddy");
            $this->copyStubDirectory("{$stubsPath}/dns", "{$configPath}/dns");
            $this->copyStubDirectory("{$stubsPath}/postgres", "{$configPath}/postgres");
            $this->copyStubDirectory("{$stubsPath}/redis", "{$configPath}/redis");
            $this->copyStubDirectory("{$stubsPath}/mailpit", "{$configPath}/mailpit");

            // Copy config.json if it doesn't exist
            if (! File::exists("{$configPath}/config.json")) {
                File::copy("{$stubsPath}/config.json", "{$configPath}/config.json");
            }

            // Copy CLAUDE.md
            File::copy("{$stubsPath}/CLAUDE.md", "{$configPath}/CLAUDE.md");

            return true;
        });

        // 3. Generate initial Caddyfile
        $this->task('Generating Caddyfile', function () use ($caddyfileGenerator) {
            $caddyfileGenerator->generate();

            return true;
        });

        // 4. Create docker network
        $this->task('Creating Docker network', $dockerManager->createNetwork(...));

        // 5. Build DNS container
        $this->task('Building DNS container', function () use ($dockerManager) {
            $result = $dockerManager->build('dns');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        // 6. Pull PHP images
        $this->task('Pulling PHP images (this may take a while)', function () use ($dockerManager) {
            $result = $dockerManager->pull('php');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        // 7. Pull service images
        $this->task('Pulling Caddy image', function () use ($dockerManager) {
            $result = $dockerManager->pull('caddy');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        $this->task('Pulling Postgres image', function () use ($dockerManager) {
            $result = $dockerManager->pull('postgres');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        $this->task('Pulling Redis image', function () use ($dockerManager) {
            $result = $dockerManager->pull('redis');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        $this->task('Pulling Mailpit image', function () use ($dockerManager) {
            $result = $dockerManager->pull('mailpit');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        $this->newLine();
        $this->warn('Point your DNS to 127.0.0.1:');
        $this->line('  System Settings → Network → Wi-Fi → Details → DNS');
        $this->line('  Or: sudo networksetup -setdnsservers Wi-Fi 127.0.0.1');

        $this->newLine();
        $this->info('Done! Run: launchpad start');

        return self::SUCCESS;
    }

    protected function copyStubDirectory(string $source, string $destination): void
    {
        if (! File::isDirectory($source)) {
            return;
        }

        File::ensureDirectoryExists($destination);

        foreach (File::files($source) as $file) {
            $destPath = "{$destination}/{$file->getFilename()}";
            // Don't overwrite existing docker-compose.yml files
            if ($file->getFilename() === 'docker-compose.yml' && File::exists($destPath)) {
                continue;
            }
            File::copy($file->getPathname(), $destPath);
        }
    }
}
