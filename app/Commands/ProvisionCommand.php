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
        {--php= : PHP version to use (8.3, 8.4, 8.5)}
        {--db-driver= : Database driver (sqlite, pgsql)}
        {--session-driver= : Session driver (file, database, redis)}
        {--cache-driver= : Cache driver (file, database, redis)}
        {--queue-driver= : Queue driver (sync, database, redis)}
        {--minimal : Only run composer install, skip npm/build/env/migrations}
        {--fork : Fork the repository instead of importing as new}';

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
        $isFork = (bool) $this->option('fork');

        // Broadcast immediately that provisioning has started
        $this->broadcast('provisioning');

        try {
            // Step 0: Handle fork mode (fork the repository using gh repo fork)
            if ($isFork && $cloneUrl && ! $template) {
                $this->broadcast('forking');
                $forkResult = $this->forkRepository($cloneUrl, $config);
                $githubRepo = $forkResult['repo'];
                $cloneUrl = $forkResult['clone_url'];

                if ($this->aborted) {
                    return 1;
                }
            }

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

                // Step 2b: Import as new repo if cloning different user's repo (and not using template/fork)
                // This happens when user selected "Import" instead of "Fork"
                if (! $template && ! $isFork && ! $githubRepo) {
                    // Check if the cloned repo belongs to someone else
                    $sourceRepo = $this->extractRepoFromUrl($cloneUrl);
                    $username = $config->get('github_username');
                    if (! $username) {
                        $whoami = shell_exec('gh api user --jq .login 2>/dev/null');
                        if ($whoami) {
                            $username = trim($whoami);
                            $config->set('github_username', $username);
                        }
                    }

                    if ($username) {
                        $targetRepo = "{$username}/{$this->slug}";
                        $sourceOwner = explode('/', $sourceRepo)[0];

                        // If source repo owner is different from current user, import as new repo
                        if (strtolower($sourceOwner) !== strtolower((string) $username)) {
                            $this->broadcast('importing');
                            $this->importAsNewRepo($targetRepo, $visibility);
                            $githubRepo = $targetRepo;

                            if ($this->aborted) {
                                return 1;
                            }
                        } else {
                            // User is cloning their own repo, just record it
                            $githubRepo = $sourceRepo;
                        }
                    }
                }
            }

            // Early Caddy reload: Makes URL accessible immediately and starts SSL cert generation
            // This happens in parallel with the rest of setup
            $this->info("Early Caddy reload (making {$this->slug}.ccc accessible)...");
            $caddyfileGenerator->generate();
            $caddyfileGenerator->reload();
            $caddyfileGenerator->reloadPhp();

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

            // Step 5: Restart PHP container to clear any cached state
            $this->restartPhpContainer();

            // Broadcast ready status BEFORE Caddy reload
            // (Caddy reload disconnects WebSocket clients temporarily)
            $this->broadcast('ready');

            // Note: Caddy was already reloaded early (after clone) to make URL accessible immediately
            $this->info("Project {$this->slug} provisioned successfully!");

            return 0;

        } catch (\Throwable $e) {
            $this->error('Provisioning failed: '.$e->getMessage());
            $this->broadcast('failed', $e->getMessage());

            return 1;
        }
    }

    /**
     * Fork a repository using gh repo fork.
     *
     * @return array{repo: string, clone_url: string}
     */
    private function forkRepository(string $sourceUrl, ConfigManager $config): array
    {
        // Extract owner/repo from URL
        $sourceRepo = $this->extractRepoFromUrl($sourceUrl);
        $this->info("Forking repository: {$sourceRepo}");

        // Fork the repository
        $result = Process::timeout(120)->run("gh repo fork {$sourceRepo} --clone=false");

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to fork repository: '.$result->errorOutput());
        }

        // Get the fork's full name (user/repo)
        $username = $config->get('github_username');
        if (! $username) {
            $whoami = shell_exec('gh api user --jq .login 2>/dev/null');
            if ($whoami) {
                $username = trim($whoami);
                $config->set('github_username', $username);
            }
        }

        // Extract just the repo name from source
        $repoName = basename($sourceRepo);
        $forkRepo = "{$username}/{$repoName}";
        $forkUrl = "git@github.com:{$forkRepo}.git";

        $this->info("Repository forked to: {$forkRepo}");

        // Wait for GitHub to propagate
        sleep(3);

        return [
            'repo' => $forkRepo,
            'clone_url' => $forkUrl,
        ];
    }

    /**
     * Import a cloned repository as a new repo (change remote and push).
     */
    private function importAsNewRepo(string $newRepo, string $visibility): void
    {
        $this->info("Importing as new repository: {$newRepo}");

        // Create new empty repository
        $result = Process::timeout(60)->run("gh repo create {$newRepo} --{$visibility} --source=".escapeshellarg($this->projectPath).' --push');

        if (! $result->successful()) {
            // If gh repo create with --source fails, try manual approach
            $this->warn('gh repo create with --source failed, trying manual approach...');

            // Create empty repo
            $createResult = Process::timeout(60)->run("gh repo create {$newRepo} --{$visibility}");
            if (! $createResult->successful()) {
                throw new \RuntimeException('Failed to create new repository: '.$createResult->errorOutput());
            }

            // Change remote
            $newUrl = "git@github.com:{$newRepo}.git";
            Process::path($this->projectPath)->run("git remote set-url origin {$newUrl}");

            // Push all branches and tags
            Process::path($this->projectPath)->timeout(120)->run('git push -u origin --all');
            Process::path($this->projectPath)->timeout(60)->run('git push origin --tags');
        }

        $this->info("Repository imported as: {$newRepo}");
    }

    /**
     * Extract owner/repo from a git URL.
     */
    private function extractRepoFromUrl(string $url): string
    {
        // Handle git@github.com:owner/repo.git
        if (preg_match('/git@github\.com:([^\/]+\/[^\/]+?)(?:\.git)?$/', $url, $matches)) {
            return $matches[1];
        }
        // Handle https://github.com/owner/repo
        if (preg_match('/github\.com\/([^\/]+\/[^\/\s]+?)(?:\.git)?$/', $url, $matches)) {
            return $matches[1];
        }

        // Assume it's already owner/repo format
        return str_replace('.git', '', $url);
    }

    private function createGitHubRepo(string $repo, string $visibility, string $template): void
    {
        $this->info("Creating GitHub repository: {$repo} from template {$template}");

        // Check if repo already exists
        $checkResult = Process::run("gh repo view {$repo} 2>/dev/null");
        if ($checkResult->successful()) {
            throw new \RuntimeException("Repository '{$repo}' already exists. Please choose a different project name.");
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
        $isMinimal = (bool) $this->option('minimal');

        // Minimal mode: just composer install, nothing else
        if ($isMinimal) {
            $this->info('Running minimal setup (composer install only)...');
            if (file_exists("{$this->projectPath}/composer.json")) {
                $this->broadcast('installing_composer');
                $this->info('  Installing Composer dependencies...');
                Process::path($this->projectPath)->timeout(600)->run('composer install --no-interaction');
                $this->info('  Composer install completed');
            }
            $this->info('Minimal setup completed');

            return;
        }

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
            $bunPath = file_exists("{$home}/.bun/bin/bun") ? "{$home}/.bun/bin/bun" : 'bun';
            $packageManager = 'npm'; // default

            if (file_exists("{$this->projectPath}/bun.lock") || file_exists("{$this->projectPath}/bun.lockb")) {
                $packageManager = 'bun';
                $this->broadcast('installing_npm');
                // Remove lock files to allow fresh install
                @unlink("{$this->projectPath}/bunfig.toml");
                @unlink("{$this->projectPath}/bun.lock");
                @unlink("{$this->projectPath}/bun.lockb");
                $this->info('  Installing dependencies with Bun...');

                // Run bun install with try-catch for timeout handling
                $bunSuccess = false;
                try {
                    $bunResult = Process::env(['PATH' => "{$home}/.bun/bin:".getenv('PATH')])
                        ->path($this->projectPath)
                        ->timeout(180) // 3 minute timeout for bun
                        ->run("{$bunPath} install --no-progress 2>&1");

                    $bunSuccess = $bunResult->successful();
                    if (! $bunSuccess) {
                        $this->warn('  Bun install failed: '.substr($bunResult->output(), 0, 500));
                    }
                } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException) {
                    $this->warn('  Bun install timed out after 180 seconds');
                }

                if (! $bunSuccess) {
                    $this->info('  Falling back to npm...');
                    $packageManager = 'npm';
                    $npmResult = Process::path($this->projectPath)->timeout(600)->run('npm install --legacy-peer-deps 2>&1');
                    if (! $npmResult->successful()) {
                        $this->warn('  npm install failed: '.substr($npmResult->output(), 0, 500));
                    } else {
                        $this->info('  npm install completed successfully');
                    }
                } else {
                    $this->info('  Bun install completed');
                }
            } elseif (file_exists("{$this->projectPath}/pnpm-lock.yaml")) {
                $packageManager = 'pnpm';
                $this->broadcast('installing_npm');
                $this->info('  Installing dependencies with pnpm...');
                Process::path($this->projectPath)->timeout(600)->run('pnpm install');
            } elseif (file_exists("{$this->projectPath}/yarn.lock")) {
                $packageManager = 'yarn';
                $this->broadcast('installing_npm');
                $this->info('  Installing dependencies with Yarn...');
                Process::path($this->projectPath)->timeout(600)->run('yarn install');
            } else {
                $this->broadcast('installing_npm');
                $this->info('  Installing dependencies with npm...');
                $npmResult = Process::path($this->projectPath)->timeout(600)->run('npm install --legacy-peer-deps 2>&1');
                if (! $npmResult->successful()) {
                    $this->warn('  npm install failed: '.substr($npmResult->output(), 0, 500));
                } else {
                    $this->info('  npm install completed');
                }
            }

            // Run build if package.json has a build script
            $this->broadcast('building');
            $packageJson = json_decode(file_get_contents("{$this->projectPath}/package.json"), true);
            if (isset($packageJson['scripts']['build'])) {
                $this->info("  Building assets with {$packageManager}...");
                $buildResult = match ($packageManager) {
                    'bun' => Process::env(['PATH' => "{$home}/.bun/bin:".getenv('PATH')])->path($this->projectPath)->timeout(600)->run("{$bunPath} run --silent build 2>&1"),
                    'pnpm' => Process::path($this->projectPath)->timeout(600)->run('pnpm run build 2>&1'),
                    'yarn' => Process::path($this->projectPath)->timeout(600)->run('yarn run build 2>&1'),
                    default => Process::path($this->projectPath)->timeout(600)->run('npm run build 2>&1'),
                };

                if (! $buildResult->successful()) {
                    $this->warn('  Asset build failed: '.$buildResult->output());
                    // Continue provisioning - site will work but may have missing assets
                } else {
                    $this->info('  Assets built successfully');
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

        // Step 9: Configure trusted proxies for reverse proxy support (Laravel 11+)
        $this->configureTrustedProxies();

        // Write PHP version file
        $phpVersion = $this->getPhpVersion();
        file_put_contents("{$this->projectPath}/.php-version", "{$phpVersion}\n");
        // Update database with PHP version (takes precedence over .php-version file)
        app(\App\Services\DatabaseService::class)->setProjectPhpVersion(
            $this->slug,
            $this->projectPath,
            $phpVersion
        );

        $this->info('Setup completed');
    }

    private function configureEnv(): void
    {
        $envPath = "{$this->projectPath}/.env";
        if (! file_exists($envPath)) {
            return;
        }

        $env = file_get_contents($envPath);

        // Configure APP_NAME and APP_URL with the project slug/domain
        $env = $this->setEnvValue($env, 'APP_NAME', $this->slug);

        // Configure APP_URL
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

    /**
     * Get the PHP version to use for this project.
     * Priority: --php option > composer.json detection > default (8.4)
     */
    private function getPhpVersion(): string
    {
        // Check if --php option was provided
        $phpOption = $this->option('php');
        if ($phpOption && in_array($phpOption, ['8.3', '8.4', '8.5'], true)) {
            return $phpOption;
        }

        // Detect from composer.json
        return $this->detectPhpVersionFromComposer();
    }

    /**
     * Detect PHP version from composer.json requirements.
     * Always prefers the latest version unless explicitly excluded.
     */
    private function detectPhpVersionFromComposer(): string
    {
        $composerPath = "{$this->projectPath}/composer.json";
        if (! file_exists($composerPath)) {
            return '8.5'; // Default to latest
        }

        $content = file_get_contents($composerPath);
        if (! $content) {
            return '8.5';
        }

        $composer = json_decode($content, true);
        $constraint = $composer['require']['php'] ?? null;

        if (! $constraint) {
            return '8.5';
        }

        return $this->getRecommendedPhpVersion($constraint);
    }

    /**
     * Get the recommended (highest compatible) PHP version for a constraint.
     * Always prefers the latest PHP version unless explicitly excluded.
     *
     * Examples:
     * - ^8.3 → 8.5 (allows 8.3+)
     * - ^8.4 → 8.5 (allows 8.4+)
     * - ~8.3.0 → 8.3 (only allows 8.3.x)
     * - 8.4.* → 8.4 (only allows 8.4.x)
     * - <8.5 → 8.4 (excludes 8.5)
     * - >=8.3 <8.5 → 8.4 (range excludes 8.5)
     */
    private function getRecommendedPhpVersion(string $constraint): string
    {
        $constraint = trim($constraint);
        $availableVersions = ['8.5', '8.4', '8.3'];

        // Check for explicit upper bound that excludes versions
        // Patterns like: <8.5, <=8.4, <8.5.0
        if (preg_match('/<\s*(\d+)\.(\d+)/', $constraint, $matches)) {
            $maxMajor = (int) $matches[1];
            $maxMinor = (int) $matches[2];

            foreach ($availableVersions as $version) {
                [$major, $minor] = explode('.', $version);
                // Version must be strictly less than the upper bound
                if ((int) $major < $maxMajor || ((int) $major === $maxMajor && (int) $minor < $maxMinor)) {
                    return $version;
                }
            }
        }

        // Check for tilde constraint ~8.x.y which locks to 8.x.*
        // ~8.3.0 means >=8.3.0 <8.4.0
        if (preg_match('/~\s*(\d+)\.(\d+)\./', $constraint, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        // Check for wildcard constraint 8.x.* which locks to 8.x
        if (preg_match('/(\d+)\.(\d+)\.\*/', $constraint, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        // For caret (^), greater-than (>=, >), or simple version constraints,
        // the latest version is compatible
        // ^8.3 means >=8.3.0 <9.0.0, so 8.5 is fine
        // >=8.3 means 8.3 or higher, so 8.5 is fine
        return '8.5';
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

    /**
     * Configure trusted proxies for Laravel 11+ projects.
     * This is required because projects run behind Caddy reverse proxy.
     */
    private function configureTrustedProxies(): void
    {
        $bootstrapPath = "{$this->projectPath}/bootstrap/app.php";
        if (! file_exists($bootstrapPath)) {
            return;
        }

        $content = file_get_contents($bootstrapPath);

        // Check if this is Laravel 11+ (uses Application::configure)
        if (! str_contains($content, 'Application::configure')) {
            $this->info('  Skipping trusted proxies (not Laravel 11+)');

            return;
        }

        // Check if trusted proxies already configured
        if (str_contains($content, 'trustProxies')) {
            $this->info('  Trusted proxies already configured');

            return;
        }

        // Add the Request import if not present
        if (! str_contains($content, 'use Illuminate\Http\Request')) {
            $content = str_replace(
                'use Illuminate\Foundation\Application;',
                "use Illuminate\Foundation\Application;\nuse Illuminate\Http\Request;",
                $content
            );
        }

        // Add trusted proxies configuration to withMiddleware
        $trustedProxiesCode = '$middleware->trustProxies(at: "*", headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);';

        // Pattern for empty middleware callback
        $emptyPattern = '/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{\s*\/\/\s*\}\)/s';
        if (preg_match($emptyPattern, $content)) {
            $content = preg_replace(
                $emptyPattern,
                "->withMiddleware(function (Middleware \$middleware): void {\n        {$trustedProxiesCode}\n    })",
                $content
            );
        } else {
            // Pattern for middleware callback with existing content - add at the beginning
            $middlewarePattern = '/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{/s';
            if (preg_match($middlewarePattern, $content)) {
                $content = preg_replace(
                    $middlewarePattern,
                    "->withMiddleware(function (Middleware \$middleware): void {\n        {$trustedProxiesCode}\n",
                    $content
                );
            }
        }

        file_put_contents($bootstrapPath, $content);
        $this->info('  Configured trusted proxies for reverse proxy support');
    }

    /**
     * Restart the PHP container to clear any cached state.
     * This ensures the new project's environment is loaded fresh.
     */
    private function restartPhpContainer(): void
    {
        $phpVersion = $this->getPhpVersion();
        $container = 'launchpad-php-'.str_replace('.', '', $phpVersion);

        $this->info("  Restarting {$container} to clear cached state...");
        $result = Process::timeout(30)->run("docker restart {$container} 2>&1");

        if ($result->successful()) {
            $this->info('  PHP container restarted');
            // Wait a moment for container to be ready
            sleep(2);
        } else {
            $this->warn("  Failed to restart PHP container: {$result->output()}");
        }
    }

    private function abort(string $reason): never
    {
        $this->aborted = true;
        $this->error("Aborting: {$reason}");
        $this->broadcast('failed', $reason);
        exit(1);
    }
}
