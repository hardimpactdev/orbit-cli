<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\Provision\BuildAssets;
use App\Actions\Provision\CheckRepoAvailable;
use App\Actions\Provision\CloneRepository;
use App\Actions\Provision\ConfigureEnvironment;
use App\Actions\Provision\ConfigureTrustedProxies;
use App\Actions\Provision\CreateDatabase;
use App\Actions\Provision\CreateGitHubRepository;
use App\Actions\Provision\ForkRepository;
use App\Actions\Provision\GenerateAppKey;
use App\Actions\Provision\InstallComposerDependencies;
use App\Actions\Provision\InstallNodeDependencies;
use App\Actions\Provision\RestartPhpContainer;
use App\Actions\Provision\RunMigrations;
use App\Actions\Provision\RunPostInstallScripts;
use App\Actions\Provision\SetPhpVersion;
use App\Concerns\WithJsonOutput;
use App\Data\Provision\ProvisionContext;
use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\McpClient;
use App\Services\ProvisionLogger;
use App\Services\ReverbBroadcaster;
use App\Services\ServiceManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

final class SiteCreateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'site:create
        {name : Site name}
        {--github-repo= : GitHub repo to create (user/repo format)}
        {--clone= : Existing repo to clone (user/repo or git URL)}
        {--template= : Template repository (user/repo format)}
        {--visibility=private : Repository visibility (private/public)}
        {--php= : PHP version to use (8.3, 8.4, 8.5)}
        {--db-driver= : Database driver (sqlite, pgsql)}
        {--session-driver= : Session driver (file, database, redis)}
        {--cache-driver= : Cache driver (file, database, redis)}
        {--queue-driver= : Queue driver (sync, database, redis)}
        {--minimal : Only run composer install, skip npm/build/env/migrations}
        {--fork : Fork the repository instead of importing as new}
        {--organization= : GitHub organization to create the repo under (overrides personal account)}
        {--path= : Override default site path}
        {--json : Output as JSON (for programmatic use)}';

    protected $description = 'Create a new site (clone, setup, and register)';

    private bool $aborted = false;

    private ?ProvisionLogger $logger = null;

    public function handle(
        ConfigManager $config,
        ReverbBroadcaster $broadcaster,
        McpClient $mcp,
        CaddyfileGenerator $caddyfileGenerator,
    ): int {
        set_time_limit(600);

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->abort('Process terminated'));
            pcntl_signal(SIGINT, fn () => $this->abort('Process interrupted'));
        }

        /** @var string $name */
        $name = $this->argument('name');
        $slug = Str::slug($name);

        // Prevent reserved names
        if (strtolower($slug) === 'orbit') {
            return $this->failWithMessage('The name "orbit" is reserved for the system.');
        }

        // Determine project path and create directory
        $paths = $config->getPaths();
        if (empty($paths)) {
            return $this->failWithMessage('No project paths configured');
        }

        /** @var string|null $pathOption */
        $pathOption = $this->option('path');
        $basePath = $pathOption ?? ($paths[0] ?? '~/projects');
        $projectPath = $this->expandPath("{$basePath}/{$slug}");

        // Check if path exists
        if (is_dir($projectPath)) {
            return $this->failWithMessage("Directory already exists: {$projectPath}");
        }

        // Create directory
        if (! mkdir($projectPath, 0755, true)) {
            return $this->failWithMessage("Failed to create directory: {$projectPath}");
        }

        // Initialize context from command options
        $context = $this->createContext($slug, $name, $projectPath, $config);

        // Initialize logger
        // When --json is passed, don't pass command to suppress console output
        $this->logger = new ProvisionLogger(
            broadcaster: $broadcaster,
            command: $this->option('json') ? null : $this,
            slug: $slug,
        );

        $this->logger->broadcast('provisioning');

        // Safeguard: Check target repo is available before proceeding
        $repoCheck = app(CheckRepoAvailable::class)->handle($context, $this->logger, $config);
        if ($repoCheck->isFailed()) {
            throw new \RuntimeException($repoCheck->error);
        }

        try {
            // Phase 1: Repository Operations
            $context = $this->handleRepositoryOperations($context, $config);

            if ($this->aborted) {
                throw new \RuntimeException('Provisioning aborted');
            }

            // Phase 2: Clone
            if ($context->cloneUrl) {
                $this->logger->broadcast('cloning');
                $result = app(CloneRepository::class)->handle($context, $this->logger);
                if ($result->isFailed()) {
                    throw new \RuntimeException($result->error);
                }

                // Import as new repo if needed
                $context = $this->handleImportIfNeeded($context, $config);
            }

            if ($this->aborted) {
                throw new \RuntimeException('Provisioning aborted');
            }

            // Early Caddy reload for accessibility
            // Note: We only reload Caddy here, not PHP-FPM, to avoid disrupting
            // the web request that triggered this provisioning
            $this->logger->info("Early Caddy reload (making {$slug}.{$context->tld} accessible)...");
            $caddyfileGenerator->generate();
            $caddyfileGenerator->reload();

            // Phase 3: Project Setup
            $this->logger->broadcast('setting_up');
            $this->runProjectSetup($context);

            if ($this->aborted) {
                throw new \RuntimeException('Provisioning aborted');
            }

            // Phase 4: Finalization
            $this->logger->broadcast('finalizing');
            if ($mcp->isConfigured()) {
                $this->registerWithSequence($mcp, $slug, $context->githubRepo);
            }

            // Restart PHP container
            $phpVersion = $this->getPhpVersionFromContext($context);
            app(RestartPhpContainer::class)->handle($phpVersion, $this->logger);

            $this->logger->broadcast('ready');
            $this->logger->info("Site {$slug} created successfully!");

            return $this->outputJsonSuccess([
                'name' => $name,
                'slug' => $slug,
                'site_slug' => $slug,
                'local_path' => $projectPath,
                'status' => 'ready',
            ]);

        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->logger->broadcast('failed', $e->getMessage());

            // Cleanup directory if empty
            if (is_dir($projectPath) && ! glob("{$projectPath}/*")) {
                rmdir($projectPath);
            }

            if ($this->wantsJson()) {
                $this->outputJsonError($e->getMessage());
            }

            return ExitCode::GeneralError->value;
        }
    }

    private function createContext(string $slug, string $name, string $projectPath, ConfigManager $config): ProvisionContext
    {
        $tld = $config->get('tld') ?? 'ccc';

        // Normalize clone URL (convert git@github.com:owner/repo.git or https URLs to owner/repo format)
        $cloneUrl = $this->option('clone');
        if ($cloneUrl) {
            if (preg_match('/github\.com[:\\/]([^\\/]+\\/[^\\/\\s]+?)(?:\\.git)?$/', $cloneUrl, $matches)) {
                $cloneUrl = $matches[1];
            } else {
                // Assume already in owner/repo format
                $cloneUrl = str_replace('.git', '', $cloneUrl);
            }
        }

        return new ProvisionContext(
            slug: $slug,
            projectPath: $projectPath,
            githubRepo: $this->option('github-repo'),
            cloneUrl: $cloneUrl,
            template: $this->option('template'),
            visibility: $this->option('visibility') ?: 'private',
            phpVersion: $this->option('php'),
            dbDriver: $this->option('db-driver'),
            sessionDriver: $this->option('session-driver'),
            cacheDriver: $this->option('cache-driver'),
            queueDriver: $this->option('queue-driver'),
            minimal: (bool) $this->option('minimal'),
            fork: (bool) $this->option('fork'),
            displayName: $name !== $slug ? $name : null,
            tld: $tld,
            organization: $this->option('organization'),
        );
    }

    private function handleRepositoryOperations(ProvisionContext $context, ConfigManager $config): ProvisionContext
    {
        $githubRepo = $context->githubRepo;
        $cloneUrl = $context->cloneUrl;

        // Handle fork mode
        if ($context->fork && $cloneUrl && ! $context->template) {
            $this->logger->broadcast('forking');
            $result = app(ForkRepository::class)->handle($context, $this->logger, $config);
            if ($result->isFailed()) {
                throw new \RuntimeException($result->error);
            }
            $githubRepo = $result->data['repo'];
            $cloneUrl = $result->data['cloneUrl'];
        }

        // Create from template
        if ($context->template) {
            // Determine target repo name
            if (! $githubRepo) {
                // Use organization if specified, otherwise fall back to personal username
                $owner = $context->getGitHubOwner($this->getGitHubUsername($config));
                if ($owner) {
                    $githubRepo = "{$owner}/{$context->slug}";
                }
            }

            if ($githubRepo) {
                $this->logger->broadcast('creating_repo');
                $result = app(CreateGitHubRepository::class)->handle($context, $this->logger, $githubRepo);
                if ($result->isFailed()) {
                    throw new \RuntimeException($result->error);
                }
                $githubRepo = $result->data['repo'];
                $cloneUrl = $result->data['cloneUrl'];
            }
        }

        // Return updated context
        return new ProvisionContext(
            slug: $context->slug,
            projectPath: $context->projectPath,
            githubRepo: $githubRepo,
            cloneUrl: $cloneUrl,
            template: $context->template,
            visibility: $context->visibility,
            phpVersion: $context->phpVersion,
            dbDriver: $context->dbDriver,
            sessionDriver: $context->sessionDriver,
            cacheDriver: $context->cacheDriver,
            queueDriver: $context->queueDriver,
            minimal: $context->minimal,
            fork: $context->fork,
            displayName: $context->displayName,
            tld: $context->tld,
            organization: $context->organization,
        );
    }

    private function handleImportIfNeeded(ProvisionContext $context, ConfigManager $config): ProvisionContext
    {
        // Skip if using template or fork, or if githubRepo already set
        if ($context->template || $context->fork || $context->githubRepo) {
            return $context;
        }

        // Use organization if specified, otherwise fall back to personal username
        $owner = $context->getGitHubOwner($this->getGitHubUsername($config));
        if (! $owner) {
            return $context;
        }

        $sourceRepo = $this->extractRepoFromUrl($context->cloneUrl);
        $sourceOwner = explode('/', $sourceRepo)[0];

        // If source repo owner is different, import as new repo
        if (strtolower($sourceOwner) !== strtolower($owner)) {
            $targetRepo = "{$owner}/{$context->slug}";
            $this->logger->broadcast('importing');
            $this->importAsNewRepo($targetRepo, $context->visibility, $context->projectPath);

            return new ProvisionContext(
                slug: $context->slug,
                projectPath: $context->projectPath,
                githubRepo: $targetRepo,
                cloneUrl: $context->cloneUrl,
                template: $context->template,
                visibility: $context->visibility,
                phpVersion: $context->phpVersion,
                dbDriver: $context->dbDriver,
                sessionDriver: $context->sessionDriver,
                cacheDriver: $context->cacheDriver,
                queueDriver: $context->queueDriver,
                minimal: $context->minimal,
                fork: $context->fork,
                displayName: $context->displayName,
                tld: $context->tld,
                organization: $context->organization,
            );
        }

        // User is cloning their own repo
        return new ProvisionContext(
            slug: $context->slug,
            projectPath: $context->projectPath,
            githubRepo: $sourceRepo,
            cloneUrl: $context->cloneUrl,
            template: $context->template,
            visibility: $context->visibility,
            phpVersion: $context->phpVersion,
            dbDriver: $context->dbDriver,
            sessionDriver: $context->sessionDriver,
            cacheDriver: $context->cacheDriver,
            queueDriver: $context->queueDriver,
            minimal: $context->minimal,
            fork: $context->fork,
            displayName: $context->displayName,
            tld: $context->tld,
            organization: $context->organization,
        );
    }

    private function runProjectSetup(ProvisionContext $context): void
    {
        // Minimal mode: just composer install
        if ($context->minimal) {
            $this->logger->info('Running minimal setup (composer install only)...');
            $this->logger->broadcast('installing_composer');
            $result = app(InstallComposerDependencies::class)->handle($context, $this->logger);
            if ($result->isFailed()) {
                throw new \RuntimeException($result->error);
            }
            $this->logger->info('Minimal setup completed');

            return;
        }

        $this->logger->info('Running project setup...');

        // Step 1: Composer install (no scripts)
        $this->logger->broadcast('installing_composer');
        $result = app(InstallComposerDependencies::class)->handle($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error);
        }

        // Step 2: Node dependencies
        $this->logger->broadcast('installing_npm');
        $nodeResult = app(InstallNodeDependencies::class)->handle($context, $this->logger);
        if ($nodeResult->isFailed()) {
            throw new \RuntimeException($nodeResult->error);
        }
        $packageManager = $nodeResult->data['packageManager'] ?? 'npm';

        // Step 3: Build assets
        $this->logger->broadcast('building');
        if ($packageManager) {
            $result = app(BuildAssets::class)->handle($context, $this->logger, $packageManager);
            if ($result->isFailed()) {
                throw new \RuntimeException($result->error);
            }
        }

        // Step 4: Configure environment
        $result = app(ConfigureEnvironment::class)->handle($context, $this->logger, app(ServiceManager::class));
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error);
        }

        // Step 5: Create database (if PostgreSQL)
        $result = app(CreateDatabase::class)->handle($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error);
        }

        // Step 6: Generate app key
        $result = app(GenerateAppKey::class)->handle($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error);
        }

        // Step 7: Run migrations
        $result = app(RunMigrations::class)->handle($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error);
        }

        // Step 8: Run post-install scripts
        $result = app(RunPostInstallScripts::class)->handle($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error);
        }

        // Step 9: Configure trusted proxies
        $result = app(ConfigureTrustedProxies::class)->handle($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error);
        }

        // Step 10: Set PHP version
        $result = app(SetPhpVersion::class)->handle($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error);
        }

        $this->logger->info('Setup completed');
    }

    private function getPhpVersionFromContext(ProvisionContext $context): string
    {
        // Try to read from .php-version file
        $versionFile = "{$context->projectPath}/.php-version";
        if (file_exists($versionFile)) {
            return trim(file_get_contents($versionFile));
        }

        return $context->phpVersion ?? '8.5';
    }

    private function getGitHubUsername(ConfigManager $config): ?string
    {
        $username = $config->get('github_username');
        if ($username) {
            return $username;
        }

        $whoami = shell_exec('gh api user --jq .login 2>/dev/null');
        if ($whoami) {
            $username = trim($whoami);
            $config->set('github_username', $username);

            return $username;
        }

        return null;
    }

    private function extractRepoFromUrl(?string $url): string
    {
        if (! $url) {
            return '';
        }

        // Handle git@github.com:owner/repo.git format
        if (preg_match('/github\.com[:\\/]([^\\/]+\\/[^\\/\\s]+?)(?:\\.git)?$/', $url, $matches)) {
            return $matches[1];
        }

        // Assume it's already owner/repo format
        return str_replace('.git', '', $url);
    }

    private function abort(string $reason): void
    {
        $this->aborted = true;
        $this->logger?->error("Aborting: {$reason}");
        $this->logger?->broadcast('failed', $reason);
    }

    private function importAsNewRepo(string $newRepo, string $visibility, string $projectPath): void
    {
        $this->logger->info("Importing as new repository: {$newRepo}");

        // Remove existing origin (from clone) before creating new repo
        Process::path($projectPath)->run('git remote remove origin');

        // Create new repository from the cloned source
        // Must run in the project directory for --source=. to work
        $createResult = Process::timeout(60)
            ->path($projectPath)
            ->run("gh repo create {$newRepo} --{$visibility} --source=. --push");

        if (! $createResult->successful()) {
            throw new \RuntimeException('Failed to import as new repository: '.$createResult->errorOutput());
        }

        $this->logger->info('Repository imported successfully');
    }

    private function registerWithSequence(McpClient $mcp, string $slug, ?string $githubRepo): void
    {
        $this->logger->info('Registering project with sequence...');

        try {
            $result = $mcp->callTool('project_add', [
                'slug' => $slug,
                'github_repo' => $githubRepo,
            ]);

            if (! ($result['success'] ?? false)) {
                $this->logger->warn('Failed to register with sequence: '.($result['error'] ?? 'Unknown error'));
            } else {
                $this->logger->info('Project registered with sequence');
            }
        } catch (\Exception $e) {
            $this->logger->warn('Sequence registration failed: '.$e->getMessage());
        }
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
