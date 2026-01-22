<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use App\Services\DeletionLogger;
use App\Services\McpClient;
use App\Services\ReverbBroadcaster;
use HardImpact\Orbit\Data\DeletionContext;
use HardImpact\Orbit\Models\Site;
use HardImpact\Orbit\Services\Deletion\DeletionPipeline;
use LaravelZero\Framework\Commands\Command;

/**
 * CLI command for deleting sites.
 *
 * This command runs the DeletionPipeline synchronously, giving real-time
 * console output while broadcasting updates to Reverb for web UI updates.
 *
 * Handles cleanup of:
 * - PostgreSQL database
 * - Project files
 * - Caddy configuration
 * - Site record in database
 * - Optional: Sequence integration cleanup
 *
 * @see \HardImpact\Orbit\Services\Deletion\DeletionPipeline
 */
final class SiteDeleteCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'site:delete
        {slug? : Site slug to delete}
        {--slug= : Site slug to delete (alternative)}
        {--id= : Site ID to delete (alternative to slug)}
        {--force : Skip confirmation prompt}
        {--delete-repo : Also delete the GitHub repository (irreversible)}
        {--keep-db : Keep the database (do not drop it)}
        {--json : Output as JSON}';

    protected $description = 'Delete a site and cascade to integrations (Sequence + VK + Linear + Database)';

    private ?DeletionLogger $logger = null;

    public function handle(
        ConfigManager $config,
        McpClient $mcp,
        ReverbBroadcaster $broadcaster,
        DeletionPipeline $pipeline,
    ): int {
        /** @var string|null $slug */
        $slug = $this->argument('slug') ?? $this->option('slug');

        /** @var string|null $id */
        $id = $this->option('id');

        // Interactive mode if TTY and no slug/id provided
        if (! $slug && ! $id && $this->input->isInteractive()) {
            /** @var string $slug */
            $slug = $this->ask('Site slug to delete');
        }

        if (! $slug && ! $id) {
            return $this->failWithMessage('Site slug or --id is required');
        }

        // Try to find the site in our database
        $site = $this->findSite($slug, $id);
        $siteId = $site?->id;

        // If we have a site record, use its slug
        if ($site) {
            $slug = $site->slug;
        }

        // Initialize logger with broadcaster for status updates
        $this->logger = new DeletionLogger(
            broadcaster: $broadcaster,
            command: $this->wantsJson() ? null : $this,
            slug: $slug,
            siteId: $siteId,
        );

        // Broadcast initial deleting status
        $this->logger->broadcast('deleting');

        // Confirmation prompt (unless --force)
        $force = (bool) $this->option('force');
        if (! $force && $this->input->isInteractive()) {
            $confirm = $this->ask(
                'Type the site slug to confirm deletion',
            );

            if ($confirm !== $slug && $confirm !== $id) {
                $this->logger->broadcast('delete_failed', 'Confirmation failed');

                return $this->failWithMessage('Confirmation failed. Deletion cancelled.');
            }
        }

        $meta = [];
        $warnings = [];

        // Try to delete from Sequence if configured (non-fatal if fails)
        if ($mcp->isConfigured()) {
            $this->logger->broadcast('removing_sequence');
            try {
                $result = $mcp->callTool('delete-site', [
                    'slug' => $slug,
                    'id' => $id ? (int) $id : null,
                    'confirm_slug' => $slug ?? $this->getSlugFromId($mcp, (int) $id),
                    'delete_github_repo' => (bool) $this->option('delete-repo'),
                ]);
                $meta = $result['meta'] ?? [];
                $this->logger->info('Deleted from sequence');

                // Use Sequence's slug if we didn't have one
                if (! $slug && isset($meta['slug'])) {
                    $slug = $meta['slug'];
                }
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                // Truncate HTML error responses
                if (str_contains($errorMsg, '<!DOCTYPE')) {
                    $errorMsg = 'Sequence MCP endpoint returned 404';
                }
                $warnings[] = 'Sequence delete failed: '.$errorMsg;
                $this->logger->warn('Sequence delete failed (continuing with local delete)');
            }
        } else {
            $this->logger->warn('Sequence not configured - skipping integration cleanup');
        }

        // Build deletion context
        $context = $this->buildDeletionContext($site, $slug, $config);

        // Run the deletion pipeline
        $result = $pipeline->run($context, $this->logger);

        if ($result->isFailed()) {
            $this->logger->broadcast('delete_failed', $result->error);

            return $this->failWithMessage($result->error ?? 'Deletion failed');
        }

        // Delete Site record from database (if exists)
        if ($site) {
            $site->delete();
            $this->logger->info('Site record deleted from database');
        }

        // Broadcast successful deletion
        $this->logger->broadcast('deleted');

        $response = [
            'message' => 'Site deleted successfully',
            'slug' => $slug,
            'deleted' => array_merge($meta, [
                'database' => ! $this->option('keep-db'),
                'files' => true,
            ]),
        ];

        if ($warnings !== []) {
            $response['warnings'] = $warnings;
        }

        return $this->outputJsonSuccess($response);
    }

    /**
     * Find site by slug or ID.
     */
    private function findSite(?string $slug, ?string $id): ?Site
    {
        if ($id) {
            return Site::find((int) $id);
        }

        if ($slug) {
            return Site::where('slug', $slug)->first();
        }

        return null;
    }

    /**
     * Build deletion context from site or manual lookup.
     */
    private function buildDeletionContext(?Site $site, ?string $slug, ConfigManager $config): DeletionContext
    {
        if ($site) {
            return DeletionContext::fromSite($site, (bool) $this->option('keep-db'))
                ->withDatabaseFromEnv();
        }

        // Fallback: build context manually from path lookup
        $projectPath = $this->findLocalPath($config, $slug);

        $context = new DeletionContext(
            slug: $slug ?? 'unknown',
            projectPath: $projectPath ?? '',
            siteId: null,
            keepDatabase: (bool) $this->option('keep-db'),
            keepRepository: true,
            dbConnection: null,
            dbName: null,
            tld: $config->getTld(),
        );

        return $context->withDatabaseFromEnv();
    }

    private function getSlugFromId(McpClient $mcp, int $id): string
    {
        $result = $mcp->callTool('get-site', ['id' => $id]);

        return $result['meta']['slug'] ?? throw new \RuntimeException('Could not retrieve site slug');
    }

    private function findLocalPath(ConfigManager $config, ?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }

        $paths = $config->getPaths();
        foreach ($paths as $basePath) {
            $expandedPath = $this->expandPath($basePath);
            $sitePath = "{$expandedPath}/{$slug}";
            if (is_dir($sitePath)) {
                return $sitePath;
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

    private function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            $this->outputJsonError($message);
        } else {
            $this->error($message);
        }

        // Broadcast failure if logger is initialized
        $this->logger?->broadcast('delete_failed', $message);

        return ExitCode::GeneralError->value;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json') || ! $this->input->isInteractive();
    }
}
