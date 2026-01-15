<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\DeletionLogger;
use App\Services\McpClient;
use App\Services\ReverbBroadcaster;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class ProjectDeleteCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'project:delete
        {slug? : Project slug to delete}
        {--slug= : Project slug to delete (alternative)}
        {--id= : Project ID to delete (alternative to slug)}
        {--force : Skip confirmation prompt}
        {--delete-repo : Also delete the GitHub repository (irreversible)}
        {--keep-db : Keep the database (do not drop it)}
        {--json : Output as JSON}';

    protected $description = 'Delete a project and cascade to integrations (Sequence + VK + Linear + Database)';

    private ?DeletionLogger $logger = null;

    public function handle(
        ConfigManager $config,
        CaddyfileGenerator $caddy,
        McpClient $mcp,
        ReverbBroadcaster $broadcaster,
    ): int {
        /** @var string|null $slug */
        $slug = $this->argument('slug') ?? $this->option('slug');

        /** @var string|null $id */
        $id = $this->option('id');

        // Interactive mode if TTY and no slug/id provided
        if (! $slug && ! $id && $this->input->isInteractive()) {
            /** @var string $slug */
            $slug = $this->ask('Project slug to delete');
        }

        if (! $slug && ! $id) {
            return $this->failWithMessage('Project slug or --id is required');
        }

        // Initialize logger with broadcaster for status updates
        $this->logger = new DeletionLogger(
            broadcaster: $broadcaster,
            command: $this->wantsJson() ? null : $this,
            slug: $slug,
        );

        // Broadcast initial deleting status
        $this->logger->broadcast('deleting');

        // Confirmation prompt (unless --force)
        $force = (bool) $this->option('force');
        if (! $force && $this->input->isInteractive()) {
            $confirm = $this->ask(
                'Type the project slug to confirm deletion',
            );

            if ($confirm !== $slug && $confirm !== $id) {
                $this->logger->broadcast('delete_failed', 'Confirmation failed');

                return $this->failWithMessage('Confirmation failed. Deletion cancelled.');
            }
        }

        $meta = [];
        $warnings = [];

        // Try to delete from sequence if configured (non-fatal if fails)
        if ($mcp->isConfigured()) {
            $this->logger->broadcast('removing_sequence');
            try {
                $result = $mcp->callTool('delete-project', [
                    'slug' => $slug,
                    'id' => $id ? (int) $id : null,
                    'confirm_slug' => $slug ?? $this->getSlugFromId($mcp, (int) $id),
                    'delete_github_repo' => (bool) $this->option('delete-repo'),
                ]);
                $meta = $result['meta'] ?? [];
                $this->logger->info('Deleted from sequence');
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

        // Find local project directory
        $localPath = $this->findLocalPath($config, $slug ?? $meta['slug'] ?? null);

        // Drop database (unless --keep-db)
        if (! $this->option('keep-db')) {
            $dbResult = $this->dropDatabase($slug ?? $meta['slug'] ?? null, $localPath);
            $meta['database'] = $dbResult;

            if ($dbResult['success'] && ! empty($dbResult['database'])) {
                $this->logger->info("Database '{$dbResult['database']}' dropped");
            }
        }

        // Broadcast removing files status before directory deletion
        $this->logger->broadcast('removing_files');

        // Remove local project directory if it exists
        if ($localPath && is_dir($localPath)) {
            $shouldDelete = $force || ! $this->input->isInteractive();
            if (! $shouldDelete && $this->input->isInteractive()) {
                $shouldDelete = $this->confirm("Delete local directory {$localPath}?", true);
            }

            if ($shouldDelete) {
                $rmResult = Process::run('rm -rf '.escapeshellarg($localPath));
                if (! $rmResult->successful()) {
                    // Fallback to sudo if permission denied (e.g., files created by PHP container)
                    Process::run('sudo rm -rf '.escapeshellarg($localPath));
                }
                $meta['local_deleted'] = true;
                $this->logger->info("Local directory deleted: {$localPath}");
            }
        } elseif ($localPath === null && $slug) {
            $this->logger->warn("Local directory not found for: {$slug}");
        }

        // Broadcast successful deletion BEFORE Caddy reload
        // (Caddy reload drops WebSocket connections, so broadcast first)
        $this->logger->broadcast('deleted');

        // Regenerate Caddy config
        $caddy->generate();
        $caddy->reload();

        $response = [
            'message' => 'Project deleted successfully',
            'deleted' => $meta,
        ];

        if ($warnings !== []) {
            $response['warnings'] = $warnings;
        }

        return $this->outputJsonSuccess($response);
    }

    private function getSlugFromId(McpClient $mcp, int $id): string
    {
        $result = $mcp->callTool('get-project', ['id' => $id]);

        return $result['meta']['slug'] ?? throw new \RuntimeException('Could not retrieve project slug');
    }

    private function findLocalPath(ConfigManager $config, ?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }

        $paths = $config->get('paths', []);
        foreach ($paths as $basePath) {
            $expandedPath = $this->expandPath($basePath);
            $projectPath = "{$expandedPath}/{$slug}";
            if (is_dir($projectPath)) {
                return $projectPath;
            }
        }

        return null;
    }

    private function dropDatabase(?string $slug, ?string $localPath): array
    {
        $dbConnection = null;
        $database = null;

        if ($localPath && file_exists("{$localPath}/.env")) {
            $envContent = file_get_contents("{$localPath}/.env");

            if (preg_match('/^DB_CONNECTION=(.+)$/m', $envContent, $matches)) {
                $dbConnection = trim($matches[1]);
            }

            if (preg_match('/^DB_DATABASE=(.+)$/m', $envContent, $matches)) {
                $database = trim($matches[1]);
            }
        }

        $postgresConnections = ['pgsql', 'postgres', 'postgresql'];
        if ($dbConnection && ! in_array(strtolower($dbConnection), $postgresConnections, true)) {
            return [
                'success' => true,
                'message' => "Project uses {$dbConnection}, not PostgreSQL - skipping database drop",
                'skipped' => true,
            ];
        }

        if (! $database && $slug) {
            $database = $slug;
        }

        if (! $database) {
            return ['success' => true, 'message' => 'No database to drop'];
        }

        $containerCheck = Process::run("docker ps --filter name=launchpad-postgres --format '{{.Names}}' 2>&1");
        if (! str_contains($containerCheck->output(), 'launchpad-postgres')) {
            return ['success' => true, 'message' => 'PostgreSQL container not running'];
        }

        $checkResult = Process::run(
            "docker exec launchpad-postgres psql -U launchpad -tAc \"SELECT 1 FROM pg_database WHERE datname='{$database}'\" 2>&1"
        );

        if (! str_contains($checkResult->output(), '1')) {
            return ['success' => true, 'message' => 'Database does not exist', 'database' => $database];
        }

        Process::run(
            "docker exec launchpad-postgres psql -U launchpad -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$database}' AND pid <> pg_backend_pid();\" 2>&1"
        );

        $result = Process::run(
            "docker exec launchpad-postgres psql -U launchpad -c \"DROP DATABASE IF EXISTS \\\"{$database}\\\";\" 2>&1"
        );

        if ($result->successful()) {
            return ['success' => true, 'message' => 'Database dropped', 'database' => $database];
        }

        return ['success' => false, 'error' => $result->output(), 'database' => $database];
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
