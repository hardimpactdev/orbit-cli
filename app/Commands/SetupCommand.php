<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class SetupCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'setup
        {project : Project name or path}
        {--skip-db : Skip database creation}
        {--skip-composer : Skip composer run setup}
        {--json : Output as JSON}';

    protected $description = 'Setup a Laravel project (configure env, create database, run composer setup)';

    public function handle(ConfigManager $config): int
    {
        $project = $this->argument('project');
        $projectPath = $this->resolveProjectPath($project, $config);

        if (! is_dir($projectPath)) {
            return $this->failWithMessage("Project not found: {$projectPath}");
        }

        if (! file_exists("{$projectPath}/composer.json")) {
            return $this->failWithMessage('Not a Composer project (no composer.json found)');
        }

        $this->info("Setting up project: {$projectPath}");
        $steps = [];

        // Step 1: Configure .env for Launchpad
        $envResult = $this->configureEnv($projectPath, $config);
        $steps['env'] = $envResult;

        if (! $envResult['success']) {
            return $this->failWithMessage('Failed to configure .env: ' . ($envResult['error'] ?? 'Unknown error'));
        }

        // Step 2: Create database
        if (! $this->option('skip-db')) {
            $dbResult = $this->createDatabase($envResult['database'] ?? basename($projectPath));
            $steps['database'] = $dbResult;
        }

        // Step 3: Run composer setup (if available)
        if (! $this->option('skip-composer')) {
            $composerResult = $this->runComposerSetup($projectPath);
            $steps['composer'] = $composerResult;
        }

        $tld = $config->get('tld', 'test');
        $projectName = basename($projectPath);

        return $this->outputJsonSuccess([
            'project' => $projectName,
            'path' => $projectPath,
            'site_url' => "https://{$projectName}.{$tld}",
            'steps' => $steps,
        ]);
    }

    private function configureEnv(string $projectPath, ConfigManager $config): array
    {
        $envPath = "{$projectPath}/.env";
        $examplePath = "{$projectPath}/.env.example";

        // Copy .env.example if .env doesn't exist
        if (! file_exists($envPath)) {
            if (file_exists($examplePath)) {
                copy($examplePath, $envPath);
                $this->line('  Created .env from .env.example');
            } else {
                return ['success' => false, 'error' => 'No .env or .env.example found'];
            }
        }

        $envContent = file_get_contents($envPath);
        if ($envContent === false) {
            return ['success' => false, 'error' => 'Could not read .env file'];
        }

        $projectName = basename($projectPath);
        $tld = $config->get('tld', 'test');
        $changes = [];

        // Configure APP_URL
        $envContent = $this->setEnvValue($envContent, 'APP_URL', "https://{$projectName}.{$tld}", $changes);

        // Configure database for Docker network
        $envContent = $this->setEnvValue($envContent, 'DB_HOST', 'launchpad-postgres', $changes);
        $envContent = $this->setEnvValue($envContent, 'DB_DATABASE', $projectName, $changes);
        $envContent = $this->setEnvValue($envContent, 'DB_USERNAME', 'launchpad', $changes);
        $envContent = $this->setEnvValue($envContent, 'DB_PASSWORD', 'launchpad', $changes);

        // Configure Redis for Docker network
        $envContent = $this->setEnvValue($envContent, 'REDIS_HOST', 'launchpad-redis', $changes);

        // Configure mail for Mailpit
        $envContent = $this->setEnvValue($envContent, 'MAIL_HOST', 'launchpad-mailpit', $changes);
        $envContent = $this->setEnvValue($envContent, 'MAIL_PORT', '1025', $changes);

        // Update MAIL_FROM_ADDRESS with project domain
        $envContent = $this->setEnvValue($envContent, 'MAIL_FROM_ADDRESS', "app@{$projectName}.{$tld}", $changes);

        file_put_contents($envPath, $envContent);

        foreach ($changes as $key => $value) {
            $this->line("  Set {$key}={$value}");
        }

        return [
            'success' => true,
            'changes' => $changes,
            'database' => $projectName,
        ];
    }

    private function setEnvValue(string $content, string $key, string $value, array &$changes): string
    {
        $pattern = "/^{$key}=.*/m";
        
        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, "{$key}={$value}", $content);
            if ($newContent !== $content) {
                $changes[$key] = $value;
            }
            return $newContent ?? $content;
        }
        
        // Key doesn't exist, append it
        $changes[$key] = $value;
        return $content . "\n{$key}={$value}";
    }

    private function createDatabase(string $database): array
    {
        $this->line("  Creating database: {$database}");

        // Use Docker exec to create database in PostgreSQL container
        $result = Process::run(
            "docker exec launchpad-postgres psql -U launchpad -c \"CREATE DATABASE \\\"{$database}\\\";\" 2>&1"
        );

        $output = $result->output();
        
        if ($result->successful() || str_contains($output, 'already exists')) {
            if (str_contains($output, 'already exists')) {
                $this->line('  Database already exists');
                return ['success' => true, 'message' => 'already exists'];
            }
            $this->line('  Database created successfully');
            return ['success' => true, 'message' => 'created'];
        }

        $this->warn("  Database creation failed: {$output}");
        return ['success' => false, 'error' => $output];
    }

    private function runComposerSetup(string $projectPath): array
    {
        // Check if composer setup script exists
        $composerJson = file_get_contents("{$projectPath}/composer.json");
        if ($composerJson === false) {
            return ['success' => false, 'error' => 'Could not read composer.json'];
        }

        $composer = json_decode($composerJson, true);
        $hasSetupScript = isset($composer['scripts']['setup']);

        if ($hasSetupScript) {
            $this->line('  Running composer run setup...');
            $result = Process::path($projectPath)
                ->timeout(600)
                ->run('composer run setup 2>&1');

            if ($result->successful()) {
                $this->line('  Composer setup completed');
                return ['success' => true, 'method' => 'composer-setup'];
            } else {
                $this->warn('  Composer setup had issues: ' . $result->output());
                return ['success' => false, 'error' => $result->output(), 'method' => 'composer-setup'];
            }
        }

        // Fallback: run individual commands
        $this->line('  No composer setup script found, running individual steps...');
        
        // Composer install
        $this->line('  Running composer install...');
        Process::path($projectPath)->timeout(600)->run('composer install');

        // Key generate
        if (file_exists("{$projectPath}/artisan")) {
            $this->line('  Generating application key...');
            Process::path($projectPath)->run('php artisan key:generate');

            $this->line('  Running migrations...');
            Process::path($projectPath)->run('php artisan migrate --force');
        }

        // NPM/Bun install
        if (file_exists("{$projectPath}/package.json")) {
            if (file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bunfig.toml")) {
                $this->line('  Running bun install...');
                Process::path($projectPath)->timeout(300)->run('bun install');
            } else {
                $this->line('  Running npm install...');
                Process::path($projectPath)->timeout(300)->run('npm install');
            }
        }

        return ['success' => true, 'method' => 'fallback'];
    }

    private function resolveProjectPath(string $project, ConfigManager $config): string
    {
        // If it's already an absolute path
        if (str_starts_with($project, '/')) {
            return $project;
        }

        // If it starts with ~
        if (str_starts_with($project, '~/')) {
            return $_SERVER['HOME'] . substr($project, 1);
        }

        // Otherwise, look in configured paths
        $paths = $config->get('paths', []);
        foreach ($paths as $basePath) {
            $expandedBase = str_starts_with((string) $basePath, '~/') 
                ? $_SERVER['HOME'] . substr((string) $basePath, 1) 
                : $basePath;
            
            $fullPath = "{$expandedBase}/{$project}";
            if (is_dir($fullPath)) {
                return $fullPath;
            }
        }

        // Default to first path
        $defaultPath = $paths[0] ?? '~/projects';
        $expandedDefault = str_starts_with((string) $defaultPath, '~/') 
            ? $_SERVER['HOME'] . substr((string) $defaultPath, 1) 
            : $defaultPath;

        return "{$expandedDefault}/{$project}";
    }

    private function failWithMessage(string $message): int
    {
        if ($this->option('json')) {
            $this->outputJsonError($message);
        } else {
            $this->error($message);
        }

        return ExitCode::GeneralError->value;
    }
}
