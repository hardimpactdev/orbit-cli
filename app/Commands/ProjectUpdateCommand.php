<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class ProjectUpdateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'project:update
        {path? : Path to the project directory}
        {--site= : Site name (alternative to path)}
        {--no-git : Skip git pull (rebuild mode)}
        {--no-deps : Skip dependency installation}
        {--no-migrate : Skip database migrations}
        {--json : Output as JSON}';

    protected $description = 'Update a project (git pull + dependencies + migrations)';

    public function handle(
        ConfigManager $config,
        CaddyfileGenerator $caddy,
    ): int {
        /** @var string|null $path */
        $path = $this->argument('path');
        /** @var string|null $site */
        $site = $this->option('site');

        // Resolve path from site name if provided
        if ($site && ! $path) {
            $path = $this->resolvePathFromSite($config, $site);
            if (! $path) {
                return $this->failWithMessage("Could not find path for site: {$site}");
            }
        }

        // Interactive mode if TTY and no path provided
        if (! $path && $this->input->isInteractive()) {
            /** @var string $path */
            $path = $this->ask('Project path');
        }

        if (! $path) {
            return $this->failWithMessage('Project path is required');
        }

        $path = $this->expandPath($path);

        if (! is_dir($path)) {
            return $this->failWithMessage("Directory does not exist: {$path}");
        }

        if (! is_dir("{$path}/.git")) {
            return $this->failWithMessage("Not a git repository: {$path}");
        }

        $results = [
            'path' => $path,
            'steps' => [],
        ];

        try {
            // Step 1: Git pull (unless --no-git is set)
            if (! $this->option('no-git')) {
                $this->info('Pulling latest changes...');
                $gitResult = Process::path($path)->timeout(120)->run('git pull');

                $results['steps']['git_pull'] = [
                    'success' => $gitResult->successful(),
                    'output' => trim($gitResult->output()),
                ];

                if (! $gitResult->successful()) {
                    $results['steps']['git_pull']['error'] = $gitResult->errorOutput();

                    return $this->outputResult($results, false, 'Git pull failed');
                }
            } else {
                $this->info('Skipping git pull (rebuild mode)...');
                $results['steps']['git_pull'] = ['skipped' => true];
            }

            // Step 2: Composer install (if composer.json exists and --no-deps not set)
            if (! $this->option('no-deps') && file_exists("{$path}/composer.json")) {
                $this->info('Installing Composer dependencies...');
                $composerResult = Process::path($path)->timeout(300)->run('composer install --no-interaction');

                $results['steps']['composer'] = [
                    'success' => $composerResult->successful(),
                ];

                if (! $composerResult->successful()) {
                    $results['steps']['composer']['error'] = $composerResult->errorOutput();
                }
            }

            // Step 3: NPM/package manager install (if package.json exists and --no-deps not set)
            if (! $this->option('no-deps') && file_exists("{$path}/package.json")) {
                $packageManager = $this->detectPackageManager($path);
                $this->info("Installing dependencies with {$packageManager}...");

                $installCommand = $this->getInstallCommand($packageManager);
                $installResult = Process::path($path)->timeout(600)->run($installCommand);

                $results['steps']['npm'] = [
                    'success' => $installResult->successful(),
                    'package_manager' => $packageManager,
                ];

                if (! $installResult->successful()) {
                    $results['steps']['npm']['error'] = $installResult->errorOutput();
                }

                // Step 4: Build assets (if build script exists)
                $packageJson = json_decode(file_get_contents("{$path}/package.json"), true);
                if (isset($packageJson['scripts']['build'])) {
                    $this->info("Building assets with {$packageManager}...");

                    $buildCommand = $this->getBuildCommand($packageManager);
                    $buildResult = Process::path($path)->timeout(600)->run($buildCommand);

                    $results['steps']['build'] = [
                        'success' => $buildResult->successful(),
                        'package_manager' => $packageManager,
                    ];

                    if (! $buildResult->successful()) {
                        $results['steps']['build']['error'] = $buildResult->errorOutput();
                    }
                }
            }

            // Step 5: Run migrations (if artisan exists and --no-migrate not set)
            if (! $this->option('no-migrate') && file_exists("{$path}/artisan")) {
                $this->info('Running migrations...');
                $migrateResult = Process::path($path)->timeout(120)->run('php artisan migrate --force');

                $results['steps']['migrate'] = [
                    'success' => $migrateResult->successful(),
                ];

                if (! $migrateResult->successful()) {
                    $results['steps']['migrate']['error'] = $migrateResult->errorOutput();
                }
            }

            // Regenerate Caddy config in case anything changed
            $caddy->generate();
            $caddy->reload();

            return $this->outputResult($results, true);

        } catch (\Throwable $e) {
            return $this->failWithMessage($e->getMessage());
        }
    }

    /**
     * Detect which package manager to use based on lock files.
     */
    private function detectPackageManager(string $path): string
    {
        if (file_exists("{$path}/bun.lock") || file_exists("{$path}/bun.lockb")) {
            return 'bun';
        }

        if (file_exists("{$path}/pnpm-lock.yaml")) {
            return 'pnpm';
        }

        if (file_exists("{$path}/yarn.lock")) {
            return 'yarn';
        }

        return 'npm';
    }

    /**
     * Get the install command for the given package manager.
     */
    private function getInstallCommand(string $packageManager): string
    {
        $home = $_SERVER['HOME'];

        return match ($packageManager) {
            'bun' => file_exists("{$home}/.bun/bin/bun")
                ? "{$home}/.bun/bin/bun install"
                : 'bun install',
            'pnpm' => 'pnpm install',
            'yarn' => 'yarn install',
            default => 'npm install',
        };
    }

    /**
     * Get the build command for the given package manager.
     */
    private function getBuildCommand(string $packageManager): string
    {
        $home = $_SERVER['HOME'];

        return match ($packageManager) {
            'bun' => file_exists("{$home}/.bun/bin/bun")
                ? "{$home}/.bun/bin/bun run build"
                : 'bun run build',
            'pnpm' => 'pnpm run build',
            'yarn' => 'yarn run build',
            default => 'npm run build',
        };
    }

    private function resolvePathFromSite(ConfigManager $config, string $site): ?string
    {
        $paths = $config->get('paths', []);
        foreach ($paths as $basePath) {
            $expandedPath = $this->expandPath($basePath);
            $projectPath = "{$expandedPath}/{$site}";
            if (is_dir($projectPath)) {
                return $projectPath;
            }
        }

        return null;
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'].substr($path, 1);
        }

        return $path;
    }

    private function outputResult(array $results, bool $success, ?string $message = null): int
    {
        if ($this->wantsJson()) {
            if ($success) {
                $this->outputJsonSuccess($results);
            } else {
                $this->output->write(json_encode([
                    'success' => false,
                    'error' => $message,
                    'data' => $results,
                ], JSON_PRETTY_PRINT));
            }
        } else {
            if ($success) {
                $this->info('Project updated successfully!');
            } else {
                $this->error($message ?? 'Update failed');
            }
        }

        return $success ? ExitCode::Success->value : ExitCode::GeneralError->value;
    }

    private function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            $this->outputJsonError($message);
        } else {
            $this->error($message);
        }

        return ExitCode::GeneralError->value;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json') || ! $this->input->isInteractive();
    }
}
