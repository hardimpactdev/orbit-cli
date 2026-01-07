<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

final class ProjectCreateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'project:create
        {name : Project name}
        {--template= : Create from template repository (user/repo format)}
        {--clone= : Clone existing repository (user/repo or git URL)}
        {--visibility=private : Repository visibility (private/public)}
        {--path= : Override default project path}
        {--json : Output as JSON}';

    protected $description = 'Create a new project (starts provisioning in background)';

    public function handle(ConfigManager $config): int
    {
        /** @var string $name */
        $name = $this->argument('name');
        $slug = Str::slug($name);

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

        // Determine GitHub repo name
        $githubRepo = null;
        $cloneUrl = null;

        if ($template) {
            // Provision command will handle GitHub repo creation
            // Just pass --template flag, provision will figure out the username
            $githubRepo = null; // Will be determined by provision command
        } elseif ($clone) {
            // Clone existing repo
            $cloneUrl = str_starts_with($clone, 'git@') || str_starts_with($clone, 'https://')
                ? $clone
                : "git@github.com:{$clone}.git";
        }

        // Step 2: Build provision command (use full path for nohup)
        $launchpadBin = realpath($_SERVER['argv'][0]) ?: '/home/launchpad/projects/launchpad-cli/launchpad';
        $provisionCmd = "HOME={$_SERVER['HOME']} {$launchpadBin} provision ".escapeshellarg($slug);

        if ($githubRepo) {
            $provisionCmd .= ' --github-repo='.escapeshellarg($githubRepo);
        }
        if ($template) {
            $provisionCmd .= ' --template='.escapeshellarg($template);
        }
        if ($cloneUrl) {
            $provisionCmd .= ' --clone-url='.escapeshellarg($cloneUrl);
        }
        $provisionCmd .= ' --visibility='.escapeshellarg($visibility);

        // Step 3: Start background process (fully detached from SSH session)
        $logFile = "/tmp/provision-{$slug}.log";

        // Write a launcher script that will run the provision command
        // This ensures complete detachment from the parent process and SSH session
        $launcherScript = "/tmp/launch-provision-{$slug}.sh";
        $scriptContent = "#!/bin/bash\n{$provisionCmd} > {$logFile} 2>&1\n";
        file_put_contents($launcherScript, $scriptContent);
        chmod($launcherScript, 0755);

        // Use 'at now' to run the script completely detached
        // 'at' creates a new session and is immune to SSH hangups
        exec("echo '{$launcherScript}' | at now 2>/dev/null || nohup {$launcherScript} > /dev/null 2>&1 &");

        // Only show info messages if not JSON mode
        if (! $this->wantsJson()) {
            $this->info("Project creation started: {$name}");
            $this->info("  Directory: {$localPath}");
            $this->info("  Log file: {$logFile}");
            $this->info('');
            $this->info('Provisioning in background. Monitor with:');
            $this->info("  tail -f {$logFile}");
        }

        return $this->outputJsonSuccess([
            'name' => $name,
            'slug' => $slug,
            'project_slug' => $slug,
            'local_path' => $localPath,
            'status' => 'provisioning',
            'log_file' => $logFile,
            'github_repo' => $githubRepo,
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
