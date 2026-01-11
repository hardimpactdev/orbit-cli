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
use App\Data\Provision\ProvisionContext;
use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\McpClient;
use App\Services\ProvisionLogger;
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
        {--name= : Display name for APP_NAME (defaults to slug)}
        {--fork : Fork the repository instead of importing as new}';

    protected $description = 'Provision a project (create repo, clone, setup, register with orchestrator)';

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

        $slug = $this->argument('slug');

        // Initialize context from command options
        $context = $this->createContext($slug, $config);

        // Initialize logger
        $this->logger = new ProvisionLogger(
            broadcaster: $broadcaster,
            command: $this,
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
            $this->logger->info("Early Caddy reload (making {$slug}.{$context->tld} accessible)...");
            $caddyfileGenerator->generate();
            $caddyfileGenerator->reload();
            $caddyfileGenerator->reloadPhp();

            // Phase 3: Project Setup
            $this->logger->broadcast('setting_up');
            $this->runProjectSetup($context);

            if ($this->aborted) {
                throw new \RuntimeException('Provisioning aborted');
            }

            // Phase 4: Finalization
            $this->logger->broadcast('finalizing');
            if ($mcp->isConfigured()) {
                $this->registerWithOrchestrator($mcp, $context->githubRepo);
            }

            // Restart PHP container
            $phpVersion = $this->getPhpVersionFromContext($context);
            app(RestartPhpContainer::class)->handle($phpVersion, $this->logger);

            $this->logger->broadcast('ready');
            $this->logger->info("Project {$slug} provisioned successfully!");

            return 0;

        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->logger->broadcast('failed', $e->getMessage());

            return 1;
        }
    }

    private function createContext(string $slug, ConfigManager $config): ProvisionContext
    {
        $paths = $config->getPaths();
        if (empty($paths)) {
            throw new \RuntimeException('No project paths configured');
        }

        $basePath = $paths[0];
        $expandedBase = str_starts_with((string) $basePath, '~/')
            ? $_SERVER['HOME'].substr((string) $basePath, 1)
            : $basePath;
        $projectPath = "{$expandedBase}/{$slug}";

        $tld = $config->get('tld') ?? 'ccc';

        return new ProvisionContext(
            slug: $slug,
            projectPath: $projectPath,
            githubRepo: $this->option('github-repo'),
            cloneUrl: $this->option('clone-url'),
            template: $this->option('template'),
            visibility: $this->option('visibility') ?: 'private',
            phpVersion: $this->option('php'),
            dbDriver: $this->option('db-driver'),
            sessionDriver: $this->option('session-driver'),
            cacheDriver: $this->option('cache-driver'),
            queueDriver: $this->option('queue-driver'),
            minimal: (bool) $this->option('minimal'),
            fork: (bool) $this->option('fork'),
            displayName: $this->option('name'),
            tld: $tld,
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
                $username = $this->getGitHubUsername($config);
                if ($username) {
                    $githubRepo = "{$username}/{$context->slug}";
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
        );
    }

    private function handleImportIfNeeded(ProvisionContext $context, ConfigManager $config): ProvisionContext
    {
        // Skip if using template or fork, or if githubRepo already set
        if ($context->template || $context->fork || $context->githubRepo) {
            return $context;
        }

        $username = $this->getGitHubUsername($config);
        if (! $username) {
            return $context;
        }

        $sourceRepo = $this->extractRepoFromUrl($context->cloneUrl);
        $sourceOwner = explode('/', $sourceRepo)[0];

        // If source repo owner is different, import as new repo
        if (strtolower($sourceOwner) !== strtolower($username)) {
            $targetRepo = "{$username}/{$context->slug}";
            $this->logger->broadcast('importing');
            $this->importAsNewRepo($targetRepo, $context->visibility);

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
        $result = app(ConfigureEnvironment::class)->handle($context, $this->logger);
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

    private function importAsNewRepo(string $newRepo, string $visibility): void
    {
        $this->logger->info("Importing as new repository: {$newRepo}");

        // Create new empty repository
        $createResult = Process::timeout(60)->run(
            "gh repo create {$newRepo} --{$visibility} --source=. --push"
        );

        if (! $createResult->successful()) {
            throw new \RuntimeException('Failed to import as new repository: '.$createResult->errorOutput());
        }

        $this->logger->info('Repository imported successfully');
    }

    private function registerWithOrchestrator(McpClient $mcp, ?string $githubRepo): void
    {
        $this->logger->info('Registering project with orchestrator...');

        try {
            $result = $mcp->callTool('project_add', [
                'slug' => $this->argument('slug'),
                'github_repo' => $githubRepo,
            ]);

            if (! ($result['success'] ?? false)) {
                $this->logger->warn('Failed to register with orchestrator: '.($result['error'] ?? 'Unknown error'));
            } else {
                $this->logger->info('Project registered with orchestrator');
            }
        } catch (\Exception $e) {
            $this->logger->warn('Orchestrator registration failed: '.$e->getMessage());
        }
    }
}
