<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

final class ProjectCreateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'project:create
        {name : Project name}
        {--template= : Create from template repository (user/repo format)}
        {--clone= : Clone existing repository (user/repo or git URL)}
        {--fork : Fork the repository instead of importing as new}
        {--visibility=private : Repository visibility (private/public)}
        {--path= : Override default project path}
        {--php= : PHP version to use (8.3, 8.4, 8.5)}
        {--db-driver= : Database driver (sqlite, pgsql)}
        {--session-driver= : Session driver (file, database, redis)}
        {--cache-driver= : Cache driver (file, database, redis)}
        {--queue-driver= : Queue driver (sync, database, redis)}
        {--minimal : Only run composer install, skip npm/build/env/migrations}
        {--json : Output as JSON}';

    protected $description = 'Create a new project';

    public function handle(ConfigManager $config): int
    {
        /** @var string $name */
        $name = $this->argument('name');
        $slug = Str::slug($name);

        // Prevent reserved names
        if (strtolower($slug) === 'launchpad') {
            return $this->failWithMessage('The name "launchpad" is reserved for the system.');
        }

        /** @var string|null $template */
        $template = $this->option('template');
        /** @var string|null $clone */
        $clone = $this->option('clone');
        /** @var string $visibility */
        $visibility = $this->option('visibility');

        // Determine local path
        /** @var string|null $pathOption */
        $pathOption = $this->option('path');
        $paths = $config->get('paths', ['~/projects']);
        $basePath = $pathOption ?? ($paths[0] ?? '~/projects');
        $localPath = $this->expandPath("{$basePath}/{$slug}");

        // Check if path exists
        if (is_dir($localPath)) {
            return $this->failWithMessage("Directory already exists: {$localPath}");
        }

        // Step 1: Create directory
        if (! mkdir($localPath, 0755, true)) {
            return $this->failWithMessage("Failed to create directory: {$localPath}");
        }

        // Determine clone URL
        $cloneUrl = null;
        if ($clone) {
            $cloneUrl = str_starts_with($clone, 'git@') || str_starts_with($clone, 'https://')
                ? $clone
                : "git@github.com:{$clone}.git";
        }

        // Build provision command arguments
        $provisionArgs = [
            'slug' => $slug,
            '--visibility' => $visibility,
        ];

        if ($template) {
            $provisionArgs['--template'] = $template;
        }
        if ($cloneUrl) {
            $provisionArgs['--clone-url'] = $cloneUrl;
        }
        if ($name !== $slug) {
            $provisionArgs['--name'] = $name;
        }
        if ($phpVersion = $this->option('php')) {
            $provisionArgs['--php'] = $phpVersion;
        }
        if ($dbDriver = $this->option('db-driver')) {
            $provisionArgs['--db-driver'] = $dbDriver;
        }
        if ($sessionDriver = $this->option('session-driver')) {
            $provisionArgs['--session-driver'] = $sessionDriver;
        }
        if ($cacheDriver = $this->option('cache-driver')) {
            $provisionArgs['--cache-driver'] = $cacheDriver;
        }
        if ($queueDriver = $this->option('queue-driver')) {
            $provisionArgs['--queue-driver'] = $queueDriver;
        }
        if ($this->option('minimal')) {
            $provisionArgs['--minimal'] = true;
        }
        if ($this->option('fork')) {
            $provisionArgs['--fork'] = true;
        }
        if ($this->wantsJson()) {
            $provisionArgs['--json'] = true;
        }

        // Run provision command synchronously
        $exitCode = Artisan::call('provision', $provisionArgs, $this->output);

        if ($exitCode !== 0) {
            // Cleanup the directory we created
            if (is_dir($localPath) && ! glob("{$localPath}/*")) {
                rmdir($localPath);
            }

            return $exitCode;
        }

        return $this->outputJsonSuccess([
            'name' => $name,
            'slug' => $slug,
            'project_slug' => $slug,
            'local_path' => $localPath,
            'status' => 'ready',
        ]);
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'].substr($path, 1);
        }

        return $path;
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
