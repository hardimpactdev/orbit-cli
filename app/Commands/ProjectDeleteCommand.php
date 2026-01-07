<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\McpClient;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class ProjectDeleteCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'project:delete
        {slug? : Project slug to delete}
        {--id= : Project ID to delete (alternative to slug)}
        {--force : Skip confirmation prompt}
        {--delete-repo : Also delete the GitHub repository (irreversible)}
        {--keep-db : Keep the database (do not drop it)}
        {--json : Output as JSON}';

    protected $description = 'Delete a project and cascade to integrations (Orchestrator + VK + Linear + Database)';

    public function handle(
        ConfigManager $config,
        CaddyfileGenerator $caddy,
        McpClient $mcp,
    ): int {
        /** @var string|null $slug */
        $slug = $this->argument('slug');

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

        // Check if MCP/Orchestrator is configured
        if (! $mcp->isConfigured()) {
            return $this->failWithMessage(
                'Orchestrator not configured. Cannot delete integrated projects.'
            );
        }

        // Confirmation prompt (unless --force)
        $force = (bool) $this->option('force');
        if (! $force && $this->input->isInteractive()) {
            $confirm = $this->ask(
                'Type the project slug to confirm deletion',
            );

            if ($confirm !== $slug && $confirm !== $id) {
                return $this->failWithMessage('Confirmation failed. Deletion cancelled.');
            }
        }

        try {
            // Call Orchestrator MCP to delete project
            $result = $mcp->callTool('delete-project', [
                'slug' => $slug,
                'id' => $id ? (int) $id : null,
                'confirm_slug' => $slug ?? $this->getSlugFromId($mcp, (int) $id),
                'delete_github_repo' => (bool) $this->option('delete-repo'),
            ]);

            $meta = $result['meta'] ?? [];

            // Find local project directory
            $localPath = $this->findLocalPath($config, $slug ?? $meta['slug'] ?? null);

            // Drop database (unless --keep-db)
            if (! $this->option('keep-db')) {
                $dbResult = $this->dropDatabase($slug ?? $meta['slug'] ?? null, $localPath);
                $meta['database'] = $dbResult;

                if ($dbResult['success'] && ! empty($dbResult['database'])) {
                    $this->info("Database '{$dbResult['database']}' dropped");
                }
            }

            // Remove local project directory if it exists
            if ($localPath && is_dir($localPath)) {
                $shouldDelete = $force || ! $this->input->isInteractive();
                if (! $shouldDelete && $this->input->isInteractive()) {
                    $shouldDelete = $this->confirm("Delete local directory {$localPath}?", true);
                }

                if ($shouldDelete) {
                    Process::run('rm -rf '.escapeshellarg($localPath));
                    $meta['local_deleted'] = true;
                    $this->info("Local directory deleted: {$localPath}");
                }
            }

            // Regenerate Caddy config
            $caddy->generate();
            $caddy->reload();

            return $this->outputJsonSuccess([
                'message' => 'Project deleted successfully',
                'deleted' => $meta,
            ]);

        } catch (\Throwable $e) {
            return $this->failWithMessage($e->getMessage());
        }
    }

    private function getSlugFromId(McpClient $mcp, int $id): string
    {
        // Get project details to retrieve slug for confirmation
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

    /**
     * Drop the project database from PostgreSQL (only if project uses PostgreSQL).
     */
    private function dropDatabase(?string $slug, ?string $localPath): array
    {
        // Check DB_CONNECTION from .env to determine if we should drop a PostgreSQL database
        $dbConnection = null;
        $database = null;

        if ($localPath && file_exists("{$localPath}/.env")) {
            $envContent = file_get_contents("{$localPath}/.env");

            // Get DB_CONNECTION
            if (preg_match('/^DB_CONNECTION=(.+)$/m', $envContent, $matches)) {
                $dbConnection = trim($matches[1]);
            }

            // Get DB_DATABASE
            if (preg_match('/^DB_DATABASE=(.+)$/m', $envContent, $matches)) {
                $database = trim($matches[1]);
            }
        }

        // Only proceed if the project uses PostgreSQL
        $postgresConnections = ['pgsql', 'postgres', 'postgresql'];
        if ($dbConnection && ! in_array(strtolower($dbConnection), $postgresConnections, true)) {
            return [
                'success' => true,
                'message' => "Project uses {$dbConnection}, not PostgreSQL - skipping database drop",
                'skipped' => true,
            ];
        }

        // If no .env or no DB_CONNECTION, check if a database with the slug name exists
        // This handles cases where the .env was already deleted or project wasn't fully set up
        if (! $database && $slug) {
            $database = $slug;
        }

        if (! $database) {
            return ['success' => true, 'message' => 'No database to drop'];
        }

        // Check if PostgreSQL container is running
        $containerCheck = Process::run("docker ps --filter name=launchpad-postgres --format '{{.Names}}' 2>&1");
        if (! str_contains($containerCheck->output(), 'launchpad-postgres')) {
            return ['success' => true, 'message' => 'PostgreSQL container not running'];
        }

        // Check if database exists
        $checkResult = Process::run(
            "docker exec launchpad-postgres psql -U launchpad -tAc \"SELECT 1 FROM pg_database WHERE datname='{$database}'\" 2>&1"
        );

        if (! str_contains($checkResult->output(), '1')) {
            return ['success' => true, 'message' => 'Database does not exist', 'database' => $database];
        }

        // Terminate existing connections to the database
        Process::run(
            "docker exec launchpad-postgres psql -U launchpad -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$database}' AND pid <> pg_backend_pid();\" 2>&1"
        );

        // Drop the database
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

        return ExitCode::GeneralError->value;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json') || ! $this->input->isInteractive();
    }
}
