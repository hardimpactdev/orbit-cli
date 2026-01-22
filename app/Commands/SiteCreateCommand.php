<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use App\Services\ProvisionLogger;
use App\Services\ReverbBroadcaster;
use HardImpact\Orbit\Data\ProvisionContext;
use HardImpact\Orbit\Enums\RepoIntent;
use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Models\Site;
use HardImpact\Orbit\Services\Provision\ProvisionPipeline;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

/**
 * CLI command for creating sites.
 *
 * This command runs the ProvisionPipeline synchronously, giving real-time
 * console output while broadcasting updates to Reverb for web UI updates.
 *
 * @see \HardImpact\Orbit\Services\Provision\ProvisionPipeline
 */
final class SiteCreateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'site:create
        {name : Site name}
        {--clone= : Existing repo to clone (user/repo or git URL)}
        {--template= : Template repository (user/repo format)}
        {--visibility=private : Repository visibility (private/public)}
        {--php= : PHP version to use (8.3, 8.4, 8.5)}
        {--db-driver= : Database driver (sqlite, pgsql)}
        {--session-driver= : Session driver (file, database, redis)}
        {--cache-driver= : Cache driver (file, database, redis)}
        {--queue-driver= : Queue driver (sync, database, redis)}
        {--fork : Fork the repository instead of importing as new}
        {--organization= : GitHub organization to create the repo under (overrides personal account)}
        {--directory= : Custom directory path for the project}
        {--json : Output as JSON (for programmatic use)}';

    protected $description = 'Create a new site (runs provisioning synchronously with real-time output)';

    private ?ProvisionLogger $logger = null;

    public function handle(
        ConfigManager $config,
        ReverbBroadcaster $broadcaster,
        ProvisionPipeline $pipeline,
    ): int {
        /** @var string $name */
        $name = $this->argument('name');
        $slug = Str::slug($name);

        // Prevent reserved names
        if (strtolower($slug) === 'orbit') {
            return $this->failWithMessage('The name "orbit" is reserved for the system.');
        }

        // Get the local environment
        $environment = Environment::getLocal();
        if (! $environment) {
            return $this->failWithMessage('No local environment found. Run "orbit init" first.');
        }

        // Check if site already exists
        if (Site::where('slug', $slug)->exists()) {
            return $this->failWithMessage("Site '{$slug}' already exists.");
        }

        // Determine project path before creating record (path is NOT NULL)
        $projectPath = $this->determineProjectPath($config, $slug);

        // Determine PHP version (default to 8.4)
        $phpVersion = $this->option('php') ?? '8.4';

        // Create the site record
        $site = Site::create([
            'environment_id' => $environment->id,
            'name' => $slug,
            'display_name' => $name,
            'slug' => $slug,
            'path' => $projectPath,
            'php_version' => $phpVersion,
            'status' => Site::STATUS_QUEUED,
        ]);

        // Initialize logger with broadcaster for Reverb updates
        $this->logger = new ProvisionLogger(
            broadcaster: $broadcaster,
            command: $this->option('json') ? null : $this,
            slug: $slug,
            siteId: $site->id,
        );

        $this->logger->info("Creating site: {$name}");
        $this->logger->broadcast('provisioning');

        try {
            // Create project directory if it doesn't exist
            if (! is_dir($projectPath)) {
                if (! mkdir($projectPath, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$projectPath}");
                }
            }

            // Build provision context
            $context = $this->buildContext($slug, $projectPath, $site->id, $environment);

            // Build options array for RepoIntent
            $options = $this->buildOptions($name);

            // Determine repo intent
            $intent = RepoIntent::fromPayload($options);

            // Phase 1: Repository Operations (fork/template)
            $context = $this->handleRepositoryOperations($context, $intent, $pipeline);

            // Phase 2: Clone repository (for clone/fork/template flows)
            if ($context->cloneUrl) {
                $this->logger->broadcast('cloning');
                $result = $pipeline->cloneRepository($context, $this->logger);
                if ($result->isFailed()) {
                    throw new \RuntimeException($result->error ?? 'Clone failed');
                }
            }

            // Phase 3: Run provision pipeline
            $this->logger->broadcast('setting_up');
            $result = $pipeline->run($context, $this->logger);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Provisioning failed');
            }

            // Phase 4: Finalize
            $this->logger->broadcast('finalizing');

            // Detect site type and public folder
            $hasPublicFolder = is_dir("{$projectPath}/public");
            $siteType = $this->detectSiteType($projectPath);
            $tld = $config->getTld();

            // Update site with final details
            $site->update([
                'status' => Site::STATUS_READY,
                'github_repo' => $context->githubRepo,
                'site_url' => "https://{$slug}.{$tld}",
                'domain' => "{$slug}.{$tld}",
                'has_public_folder' => $hasPublicFolder,
                'site_type' => $siteType,
                'error_message' => null,
            ]);

            // Broadcast ready BEFORE Caddy reload
            $this->logger->broadcast('ready');

            // Regenerate Caddyfile and reload Caddy
            if ($hasPublicFolder) {
                $this->regenerateCaddy();
            }

            $this->logger->info("Site {$slug} created successfully!");

            return $this->outputJsonSuccess([
                'name' => $name,
                'slug' => $slug,
                'site_id' => $site->id,
                'status' => 'ready',
                'site_url' => "https://{$slug}.{$tld}",
                'path' => $projectPath,
            ]);

        } catch (\Throwable $e) {
            $site->update([
                'status' => Site::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $this->logger->broadcast('failed', $e->getMessage());
            $this->logger->error($e->getMessage());

            // Cleanup empty directory
            if (is_dir($projectPath) && ! glob("{$projectPath}/*")) {
                @rmdir($projectPath);
            }

            if ($this->wantsJson()) {
                $this->outputJsonError($e->getMessage());
            }

            return ExitCode::GeneralError->value;
        }
    }

    /**
     * Determine the project path for the site.
     */
    private function determineProjectPath(ConfigManager $config, string $slug): string
    {
        // If directory option provided, use it
        if ($this->option('directory')) {
            return $this->expandPath($this->option('directory'));
        }

        // Get default path from config
        $paths = $config->getPaths();
        $basePath = $paths[0] ?? '~/projects';

        return $this->expandPath("{$basePath}/{$slug}");
    }

    /**
     * Build the provision context from command options.
     */
    private function buildContext(string $slug, string $projectPath, int $siteId, Environment $environment): ProvisionContext
    {
        $tld = $environment->tld ?? 'ccc';

        // Parse clone URL if provided
        $cloneUrl = $this->option('clone') ?? $this->option('template');
        if ($cloneUrl) {
            $cloneUrl = $this->normalizeRepoUrl($cloneUrl);
        }

        return new ProvisionContext(
            slug: $slug,
            projectPath: $projectPath,
            siteId: $siteId,
            cloneUrl: $cloneUrl,
            template: $this->option('template') ? $cloneUrl : null,
            visibility: $this->option('visibility') ?? 'private',
            phpVersion: $this->option('php'),
            dbDriver: $this->option('db-driver'),
            sessionDriver: $this->option('session-driver'),
            cacheDriver: $this->option('cache-driver'),
            queueDriver: $this->option('queue-driver'),
            fork: (bool) $this->option('fork'),
            displayName: $this->argument('name'),
            tld: $tld,
            organization: $this->option('organization'),
        );
    }

    /**
     * Build options array for RepoIntent determination.
     */
    private function buildOptions(string $name): array
    {
        $options = ['name' => $name];

        if ($this->option('clone')) {
            $options['template'] = $this->normalizeRepoUrl($this->option('clone'));
        } elseif ($this->option('template')) {
            $options['template'] = $this->normalizeRepoUrl($this->option('template'));
            $options['is_template'] = true;
        }

        if ($this->option('fork')) {
            $options['fork'] = true;
        }

        return $options;
    }

    /**
     * Handle repository operations (fork/template creation).
     */
    private function handleRepositoryOperations(
        ProvisionContext $context,
        RepoIntent $intent,
        ProvisionPipeline $pipeline
    ): ProvisionContext {
        $github = $pipeline->getGitHubService();

        // Fork flow
        if ($intent === RepoIntent::Fork && $context->cloneUrl) {
            $result = $pipeline->forkRepository($context, $this->logger);
            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Fork failed');
            }

            return $context->withRepoInfo(
                $result->data['repo'] ?? null,
                $result->data['cloneUrl'] ?? null
            );
        }

        // Template flow
        if ($intent === RepoIntent::Template && $context->template) {
            $owner = $context->getGitHubOwner($github->getUsername());
            if (! $owner) {
                throw new \RuntimeException('Could not determine GitHub username for template');
            }

            $targetRepo = "{$owner}/{$context->slug}";
            $result = $pipeline->createFromTemplate($context, $this->logger, $targetRepo);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Template creation failed');
            }

            return $context->withRepoInfo(
                $result->data['repo'] ?? null,
                $result->data['cloneUrl'] ?? null
            );
        }

        return $context;
    }

    /**
     * Normalize repo URL to owner/repo format.
     */
    private function normalizeRepoUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        // Handle git@github.com:owner/repo.git or https URLs
        if (preg_match('/github\.com[:\\/]([^\\/]+\\/[^\\/\\s]+?)(?:\\.git)?$/', $url, $matches)) {
            return $matches[1];
        }

        // Assume already in owner/repo format
        return str_replace('.git', '', $url);
    }

    /**
     * Expand ~ to home directory.
     */
    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = $_SERVER['HOME'] ?? '/home/orbit';

            return $home.substr($path, 1);
        }

        return $path;
    }

    /**
     * Detect the site type based on file structure.
     */
    private function detectSiteType(string $directory): string
    {
        $hasPublicFolder = is_dir("{$directory}/public");
        $hasArtisan = file_exists("{$directory}/artisan");
        $composerJson = "{$directory}/composer.json";

        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true);

            $type = $composer['type'] ?? null;
            if ($type === 'library' || $type === 'laravel-package') {
                return 'laravel-package';
            }

            $extra = $composer['extra'] ?? [];
            if (isset($extra['laravel']['providers']) || isset($extra['laravel']['aliases'])) {
                return 'laravel-package';
            }

            if (isset($composer['require']['laravel-zero/framework'])) {
                return 'cli';
            }
        }

        if ($hasPublicFolder && $hasArtisan) {
            return 'laravel-app';
        }

        if ($hasArtisan) {
            return 'cli';
        }

        if ($hasPublicFolder) {
            return 'web';
        }

        return 'unknown';
    }

    /**
     * Regenerate Caddyfile and reload Caddy.
     */
    private function regenerateCaddy(): void
    {
        $this->logger->info('Regenerating Caddy configuration...');

        // Call our own caddy:reload command
        $result = $this->call('caddy:reload', ['--json' => true]);

        if ($result === 0) {
            $this->logger->info('Caddy configuration reloaded');
        } else {
            $this->logger->warn('Could not reload Caddy - you may need to reload manually');
        }
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
