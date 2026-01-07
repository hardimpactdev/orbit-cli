<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\McpClient;
use App\Services\ReverbBroadcaster;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class ProvisionCommand extends Command
{
    protected $signature = 'provision
        {slug : Project slug}
        {--github-repo= : GitHub repo to create (user/repo format)}
        {--clone-url= : Existing repo URL to clone}
        {--template= : Template repository (user/repo format)}
        {--visibility=private : Repository visibility (private/public)}';

    protected $description = 'Provision a project (create repo, clone, setup, register with orchestrator)';

    private string $slug;

    private string $projectPath;

    private bool $aborted = false;

    private ?ReverbBroadcaster $broadcaster = null;

    public function handle(ConfigManager $config, ReverbBroadcaster $broadcaster, McpClient $mcp, CaddyfileGenerator $caddyfileGenerator): int
    {
        set_time_limit(600);
        $this->broadcaster = $broadcaster;

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->abort('Process terminated'));
            pcntl_signal(SIGINT, fn () => $this->abort('Process interrupted'));
        }

        $this->slug = $this->argument('slug');

        $paths = $config->getPaths();
        if (empty($paths)) {
            $this->broadcast('failed', 'No project paths configured');

            return 1;
        }

        $basePath = $paths[0];
        $expandedBase = str_starts_with((string) $basePath, '~/')
            ? $_SERVER['HOME'].substr((string) $basePath, 1)
            : $basePath;
        $this->projectPath = "{$expandedBase}/{$this->slug}";

        $githubRepo = $this->option('github-repo');
        $cloneUrl = $this->option('clone-url');
        $template = $this->option('template');
        $visibility = $this->option('visibility') ?: 'private';

        // Broadcast immediately that provisioning has started
        $this->broadcast('provisioning');

        try {
            // Step 1: Create GitHub repository from template (if requested)
            if ($template) {
                // Figure out github repo name if not provided
                if (! $githubRepo) {
                    $username = $config->get('github_username');
                    if (! $username) {
                        $whoami = shell_exec('gh api user --jq .login 2>/dev/null');
                        if ($whoami) {
                            $username = trim($whoami);
                            $config->set('github_username', $username);
                        }
                    }
                    if ($username) {
                        $githubRepo = "{$username}/{$this->slug}";
                    }
                }

                if ($githubRepo) {
                    $this->broadcast('creating_repo');
                    $this->createGitHubRepo($githubRepo, $visibility, $template);

                    if ($this->aborted) {
                        return 1;
                    }

                    // Set clone URL to the new repo
                    $cloneUrl = "git@github.com:{$githubRepo}.git";
                }
            }

            // Step 2: Clone repository
            if ($cloneUrl) {
                $this->broadcast('cloning');
                $this->cloneRepository($cloneUrl);

                if ($this->aborted) {
                    return 1;
                }
            }

            // Step 3: Run setup (composer, npm, env, etc.)
            $this->broadcast('setting_up');
            $this->runSetup();

            if ($this->aborted) {
                return 1;
            }

            // Step 4: Register with orchestrator (if configured)
            // Note: Orchestrator now handles Linear/VibeKanban creation directly via API
            $this->broadcast('finalizing');
            if ($mcp->isConfigured()) {
                $this->registerWithOrchestrator($mcp, $githubRepo);
            }

            // Broadcast ready status BEFORE Caddy reload
            // (Caddy reload disconnects WebSocket clients temporarily)
            $this->broadcast('ready');

            // Step 5: Regenerate Caddy config and reload (after broadcasting ready)
            $this->info('Regenerating Caddy configuration...');
            $caddyfileGenerator->generate();
            $caddyfileGenerator->reload();
            $caddyfileGenerator->reloadPhp();
            $this->info('Caddy reloaded');

            $this->info("Project {$this->slug} provisioned successfully!");

            return 0;

        } catch (\Throwable $e) {
            $this->error('Provisioning failed: '.$e->getMessage());
            $this->broadcast('failed', $e->getMessage());

            return 1;
        }
    }

    private function createGitHubRepo(string $repo, string $visibility, string $template): void
    {
        $this->info("Creating GitHub repository: {$repo} from template {$template}");

        // Check if repo already exists
        $checkResult = Process::run("gh repo view {$repo} 2>/dev/null");
        if ($checkResult->successful()) {
            $this->info('Repository already exists, skipping creation');

            return;
        }

        $command = "gh repo create {$repo} --{$visibility} --template ".escapeshellarg($template).' --clone=false';
        $result = Process::timeout(120)->run($command);

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to create GitHub repository: '.$result->errorOutput());
        }

        $this->info('GitHub repository created successfully');

        // Wait for GitHub to propagate
        sleep(3);
    }

    private function cloneRepository(string $repoUrl): void
    {
        $this->info("Cloning repository to {$this->projectPath}");

        // Remove empty placeholder directory if exists
        if (is_dir($this->projectPath)) {
            $files = array_diff(scandir($this->projectPath), ['.', '..']);
            if (empty($files)) {
                rmdir($this->projectPath);
            } else {
                throw new \RuntimeException("Project directory is not empty: {$this->projectPath}");
            }
        }

        $result = Process::timeout(300)->run("git clone {$repoUrl} ".escapeshellarg($this->projectPath));

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to clone repository: '.$result->errorOutput());
        }

        $this->info('Repository cloned successfully');
    }

    private function runSetup(): void
    {
        $this->info('Running project setup...');

        // Composer install
        if (file_exists("{$this->projectPath}/composer.json")) {
            $this->info('  Installing Composer dependencies...');
            Process::path($this->projectPath)->timeout(600)->run('composer install --no-interaction');
        }

        // NPM install
        if (file_exists("{$this->projectPath}/package.json")) {
            $this->info('  Installing NPM dependencies...');
            Process::path($this->projectPath)->timeout(600)->run('npm install');
        }

        // Copy .env and configure
        if (file_exists("{$this->projectPath}/.env.example") && ! file_exists("{$this->projectPath}/.env")) {
            copy("{$this->projectPath}/.env.example", "{$this->projectPath}/.env");

            // Configure common settings
            $this->configureEnv();

            // Generate Laravel key
            if (file_exists("{$this->projectPath}/artisan")) {
                Process::path($this->projectPath)->run('php artisan key:generate');
            }
        }

        // Write PHP version file
        $phpVersion = $this->detectPhpVersion();
        file_put_contents("{$this->projectPath}/.php-version", "{$phpVersion}\n");

        $this->info('Setup completed');
    }

    private function configureEnv(): void
    {
        $envPath = "{$this->projectPath}/.env";
        if (! file_exists($envPath)) {
            return;
        }

        $env = file_get_contents($envPath);

        // Configure APP_URL with the project domain
        $env = preg_replace('/^APP_URL=.*/m', "APP_URL=https://{$this->slug}.ccc", $env);

        // Configure Redis (available at localhost:6379)
        $env = preg_replace('/^REDIS_HOST=.*/m', 'REDIS_HOST=127.0.0.1', (string) $env);
        $env = preg_replace('/^REDIS_PORT=.*/m', 'REDIS_PORT=6379', (string) $env);

        // Configure database
        $env = preg_replace('/^DB_CONNECTION=.*/m', 'DB_CONNECTION=sqlite', (string) $env);
        $env = preg_replace('/^DB_DATABASE=.*/m', 'DB_DATABASE='.$this->projectPath.'/database/database.sqlite', (string) $env);

        // Note: Cache/session/queue settings are inherited from .env.example
        // The starterkit defaults to file/sync which works without Redis

        file_put_contents($envPath, $env);

        // Create SQLite database if using SQLite
        $dbPath = "{$this->projectPath}/database/database.sqlite";
        if (! file_exists($dbPath)) {
            touch($dbPath);
        }
    }

    private function detectPhpVersion(): string
    {
        $composerPath = "{$this->projectPath}/composer.json";
        if (! file_exists($composerPath)) {
            return '8.4';
        }

        $content = file_get_contents($composerPath);
        if (! $content) {
            return '8.4';
        }

        $composer = json_decode($content, true);
        $phpReq = $composer['require']['php'] ?? null;

        if ($phpReq && preg_match('/(\d+\.\d+)/', (string) $phpReq, $m)) {
            if (version_compare($m[1], '8.4', '>=')) {
                return '8.4';
            }
            if (version_compare($m[1], '8.3', '>=')) {
                return '8.3';
            }
        }

        return '8.4';
    }

    /**
     * Register with orchestrator.
     * Note: Orchestrator now handles Linear/VibeKanban creation directly via API.
     */
    private function registerWithOrchestrator(McpClient $mcp, ?string $githubRepo): void
    {
        $this->info('Registering project with orchestrator...');

        try {
            $params = [
                'name' => $this->slug,
                'slug' => $this->slug,
                'local_path' => $this->projectPath,
            ];

            // Pass github_repo if available (user/repo format)
            if ($githubRepo) {
                $params['github_repo'] = $githubRepo;
            }

            $mcp->callTool('create-project', $params);
            $this->info('Registered with orchestrator (Linear/VibeKanban handled by orchestrator)');
        } catch (\Throwable $e) {
            $this->warn('Orchestrator registration failed: '.$e->getMessage());
            // Non-fatal - project is still usable
        }
    }

    private function broadcast(string $status, ?string $error = null): void
    {
        if (! $this->broadcaster?->isEnabled()) {
            return;
        }

        $eventData = [
            'slug' => $this->slug,
            'status' => $status,
            'error' => $error,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->broadcaster->broadcast("project.{$this->slug}", 'project.provision.status', $eventData);
        $this->broadcaster->broadcast('provisioning', 'project.provision.status', $eventData);
    }

    private function abort(string $reason): never
    {
        $this->aborted = true;
        $this->error("Aborting: {$reason}");
        $this->broadcast('failed', $reason);
        exit(1);
    }
}
