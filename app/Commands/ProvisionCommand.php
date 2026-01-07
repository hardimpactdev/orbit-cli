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
        {--visibility=private : Repository visibility (private/public)}
        {--db-driver= : Database driver (sqlite, pgsql)}
        {--session-driver= : Session driver (file, database, redis)}
        {--cache-driver= : Cache driver (file, database, redis)}
        {--queue-driver= : Queue driver (sync, database, redis)}';

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

        // Step 1: Composer install WITHOUT running scripts
        // This prevents setup scripts from running before .env is configured
        if (file_exists("{$this->projectPath}/composer.json")) {
            $this->broadcast('installing_composer');
            $this->info('  Installing Composer dependencies (no scripts)...');
            Process::path($this->projectPath)->timeout(600)->run('composer install --no-interaction --no-scripts');
        }

        // Step 2: Install JS dependencies (detect package manager from lockfile)
        if (file_exists("{$this->projectPath}/package.json")) {
            $home = $_SERVER['HOME'];
            if (file_exists("{$this->projectPath}/bun.lock") || file_exists("{$this->projectPath}/bun.lockb")) {
                $this->broadcast('installing_npm');
                $this->info('  Installing dependencies with Bun...');
                $bunPath = file_exists("{$home}/.bun/bin/bun") ? "{$home}/.bun/bin/bun" : 'bun';
                Process::path($this->projectPath)->timeout(600)->run("{$bunPath} install");
            } elseif (file_exists("{$this->projectPath}/pnpm-lock.yaml")) {
                $this->info('  Installing dependencies with pnpm...');
                Process::path($this->projectPath)->timeout(600)->run('pnpm install');
            } elseif (file_exists("{$this->projectPath}/yarn.lock")) {
                $this->info('  Installing dependencies with Yarn...');
                Process::path($this->projectPath)->timeout(600)->run('yarn install');
            } else {
                $this->info('  Installing dependencies with npm...');
                Process::path($this->projectPath)->timeout(600)->run('npm install');
            }

            // Run build if package.json has a build script
            $this->broadcast('building');
            $packageJson = json_decode(file_get_contents("{$this->projectPath}/package.json"), true);
            if (isset($packageJson['scripts']['build'])) {
                $this->info('  Building assets...');
                $home = $_SERVER['HOME'];
                $bunPath = file_exists("{$home}/.bun/bin/bun") ? "{$home}/.bun/bin/bun" : 'bun';
                if (file_exists("{$this->projectPath}/bun.lock") || file_exists("{$this->projectPath}/bun.lockb")) {
                    Process::env(['PATH' => "{$home}/.bun/bin:".getenv('PATH')])->path($this->projectPath)->timeout(600)->run("{$bunPath} run build 2>&1");
                } else {
                    Process::path($this->projectPath)->timeout(600)->run('npm run build 2>&1');
                }
            }
        }

        // Step 3: Copy .env and configure BEFORE running any Laravel commands
        if (file_exists("{$this->projectPath}/.env.example") && ! file_exists("{$this->projectPath}/.env")) {
            copy("{$this->projectPath}/.env.example", "{$this->projectPath}/.env");
        }

        // Step 4: Configure .env with user's driver choices
        $this->configureEnv();

        // Step 5: Create PostgreSQL database if needed
        if ($this->option('db-driver') === 'pgsql') {
            $this->createPostgresDatabase();
        }

        // Step 6: Generate Laravel key
        if (file_exists("{$this->projectPath}/artisan")) {
            $this->info('  Generating application key...');
            Process::path($this->projectPath)->run('php artisan key:generate');
        }

        // Step 7: Run migrations
        if (file_exists("{$this->projectPath}/artisan")) {
            $this->info('  Running migrations...');
            Process::path($this->projectPath)->timeout(120)->run('php artisan migrate --force');
        }

        // Step 8: Run composer scripts (like post-install-cmd) if they exist
        // This handles templates that have setup scripts
        if (file_exists("{$this->projectPath}/composer.json")) {
            $composerJson = json_decode(file_get_contents("{$this->projectPath}/composer.json"), true);
            if (isset($composerJson['scripts']['post-autoload-dump']) || isset($composerJson['scripts']['post-install-cmd'])) {
                $this->info('  Running post-install scripts...');
                Process::path($this->projectPath)->timeout(300)->run('composer run-script post-autoload-dump 2>/dev/null || true');
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

        // Get driver options (null = keep template default)
        $dbDriver = $this->option('db-driver');
        $sessionDriver = $this->option('session-driver');
        $cacheDriver = $this->option('cache-driver');
        $queueDriver = $this->option('queue-driver');

        // Database configuration
        if ($dbDriver === 'pgsql') {
            $env = $this->setEnvValue($env, 'DB_CONNECTION', 'pgsql');
            $env = $this->setEnvValue($env, 'DB_HOST', 'launchpad-postgres');
            $env = $this->setEnvValue($env, 'DB_PORT', '5432');
            $env = $this->setEnvValue($env, 'DB_DATABASE', $this->slug);
            $env = $this->setEnvValue($env, 'DB_USERNAME', 'launchpad');
            $env = $this->setEnvValue($env, 'DB_PASSWORD', 'launchpad');
        } elseif ($dbDriver === 'sqlite') {
            $env = $this->setEnvValue($env, 'DB_CONNECTION', 'sqlite');
            // Create SQLite database file
            $sqlitePath = "{$this->projectPath}/database/database.sqlite";
            if (! file_exists($sqlitePath)) {
                if (! is_dir(dirname($sqlitePath))) {
                    mkdir(dirname($sqlitePath), 0755, true);
                }
                touch($sqlitePath);
            }
        }
        // If null, keep template default

        // Session driver
        if ($sessionDriver) {
            $env = $this->setEnvValue($env, 'SESSION_DRIVER', $sessionDriver);
        }

        // Cache driver
        if ($cacheDriver) {
            $env = $this->setEnvValue($env, 'CACHE_STORE', $cacheDriver);
        }

        // Queue driver
        if ($queueDriver) {
            $env = $this->setEnvValue($env, 'QUEUE_CONNECTION', $queueDriver);
        }

        // Configure Redis host if any driver uses Redis
        $needsRedis = in_array('redis', [$sessionDriver, $cacheDriver, $queueDriver], true);
        if ($needsRedis) {
            $env = $this->setEnvValue($env, 'REDIS_HOST', 'launchpad-redis');
            $env = $this->setEnvValue($env, 'REDIS_PORT', '6379');
        }

        file_put_contents($envPath, $env);
        $this->info('  Environment configured');
    }

    /**
     * Set or update an environment variable in .env content.
     */
    private function setEnvValue(string $env, string $key, string $value): string
    {
        // Escape value if it contains spaces or special characters
        if (preg_match('/[\s#]/', $value)) {
            $value = '"'.$value.'"';
        }

        // Check if key exists
        if (preg_match("/^{$key}=.*/m", $env)) {
            // Update existing key
            return preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
        }

        // Add new key at end
        return rtrim($env)."\n{$key}={$value}\n";
    }

    /**
     * Create PostgreSQL database for the project.
     */
    private function createPostgresDatabase(): void
    {
        $this->info("  Creating PostgreSQL database: {$this->slug}");

        // Check if PostgreSQL container is running
        $containerCheck = Process::run("docker ps --filter name=launchpad-postgres --format '{{.Names}}' 2>&1");
        if (! str_contains($containerCheck->output(), 'launchpad-postgres')) {
            $this->warn('  PostgreSQL container not running, skipping database creation');

            return;
        }

        // Check if database already exists
        $checkResult = Process::run(
            "docker exec launchpad-postgres psql -U launchpad -tAc \"SELECT 1 FROM pg_database WHERE datname='{$this->slug}'\" 2>&1"
        );

        if (str_contains($checkResult->output(), '1')) {
            $this->info('  Database already exists');

            return;
        }

        // Create database
        $result = Process::run(
            "docker exec launchpad-postgres psql -U launchpad -c \"CREATE DATABASE \\\"{$this->slug}\\\";\" 2>&1"
        );

        if ($result->successful()) {
            $this->info('  PostgreSQL database created');
        } else {
            $this->warn('  Failed to create database: '.$result->output());
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
