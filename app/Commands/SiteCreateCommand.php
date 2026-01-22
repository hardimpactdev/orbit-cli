<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use App\Services\ProvisionLogger;
use App\Services\ReverbBroadcaster;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

/**
 * CLI command for creating sites.
 *
 * This command dispatches a job to the Horizon queue via the orbit-core API.
 * All provisioning logic lives in CreateSiteJob in orbit-core.
 *
 * @see \HardImpact\Orbit\Jobs\CreateSiteJob
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
        {--wait : Wait for provisioning to complete (polls for status)}
        {--json : Output as JSON (for programmatic use)}';

    protected $description = 'Create a new site (dispatches provisioning job to Horizon)';

    private ?ProvisionLogger $logger = null;

    public function handle(
        ConfigManager $config,
        ReverbBroadcaster $broadcaster,
    ): int {
        /** @var string $name */
        $name = $this->argument('name');
        $slug = Str::slug($name);

        // Prevent reserved names
        if (strtolower($slug) === 'orbit') {
            return $this->failWithMessage('The name "orbit" is reserved for the system.');
        }

        // Initialize logger for CLI output
        $this->logger = new ProvisionLogger(
            broadcaster: $broadcaster,
            command: $this->option('json') ? null : $this,
            slug: $slug,
        );

        $this->logger->info("Creating site: {$name}");

        // Build API payload
        $payload = $this->buildPayload($name);

        // Get API URL from TLD
        $tld = $config->getTld();
        $apiUrl = "https://orbit.{$tld}/api/sites";

        $this->logger->info('Dispatching provisioning job via API...');

        try {
            // Call the orbit-core API to create the site and dispatch the job
            $response = Http::timeout(30)
                ->withOptions(['verify' => false]) // Allow self-signed certs
                ->acceptJson()
                ->post($apiUrl, $payload);

            if (! $response->successful()) {
                $error = $response->json('error') ?? $response->body();
                throw new \RuntimeException("API error: {$error}");
            }

            $data = $response->json();

            if (! ($data['success'] ?? false)) {
                throw new \RuntimeException($data['error'] ?? 'Failed to create site');
            }

            $siteSlug = $data['slug'] ?? $slug;
            $this->logger->info("Site creation queued: {$siteSlug}");

            // If --wait flag, poll for completion
            if ($this->option('wait')) {
                return $this->waitForCompletion($config, $siteSlug);
            }

            // Return immediately - provisioning happens in background
            return $this->outputJsonSuccess([
                'name' => $name,
                'slug' => $siteSlug,
                'site_slug' => $siteSlug,
                'status' => 'queued',
                'message' => 'Site creation queued. Provisioning runs in background via Horizon.',
            ]);

        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());

            if ($this->wantsJson()) {
                $this->outputJsonError($e->getMessage());
            }

            return ExitCode::GeneralError->value;
        }
    }

    /**
     * Build the API payload from command options.
     *
     * The API uses 'template' field for both:
     * - Clone URLs (--clone): is_template = false
     * - GitHub templates (--template): is_template = true
     */
    private function buildPayload(string $name): array
    {
        $payload = [
            'name' => $name,
        ];

        // Handle clone vs template (mutually exclusive)
        // Both use the 'template' API field, distinguished by 'is_template'
        if ($this->option('clone')) {
            $payload['template'] = $this->normalizeRepoUrl($this->option('clone'));
            $payload['is_template'] = false;
        } elseif ($this->option('template')) {
            $payload['template'] = $this->normalizeRepoUrl($this->option('template'));
            $payload['is_template'] = true;
        }

        // Map other CLI options to API payload
        $optionMap = [
            'visibility' => 'visibility',
            'php' => 'php_version',
            'db-driver' => 'db_driver',
            'session-driver' => 'session_driver',
            'cache-driver' => 'cache_driver',
            'queue-driver' => 'queue_driver',
            'organization' => 'org',
        ];

        foreach ($optionMap as $cliOption => $apiKey) {
            $value = $this->option($cliOption);
            if ($value !== null) {
                $payload[$apiKey] = $value;
            }
        }

        // Handle fork flag (only applies when cloning, not templates)
        if ($this->option('fork') && $this->option('clone')) {
            $payload['fork'] = true;
        }

        return $payload;
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
     * Poll for provisioning completion.
     */
    private function waitForCompletion(ConfigManager $config, string $slug): int
    {
        $tld = $config->getTld();
        $statusUrl = "https://orbit.{$tld}/api/sites/{$slug}/provision-status";

        $this->logger->info('Waiting for provisioning to complete...');

        $maxAttempts = 120; // 10 minutes max (120 * 5 seconds)
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $response = Http::timeout(10)
                    ->withOptions(['verify' => false])
                    ->acceptJson()
                    ->get($statusUrl);

                if ($response->successful()) {
                    $data = $response->json();
                    $status = $data['status'] ?? 'unknown';

                    if ($status === 'ready') {
                        $this->logger->info('Site provisioning completed!');

                        return $this->outputJsonSuccess([
                            'slug' => $slug,
                            'status' => 'ready',
                            'site_url' => $data['site_url'] ?? "https://{$slug}.{$tld}",
                        ]);
                    }

                    if ($status === 'failed') {
                        $error = $data['error'] ?? 'Provisioning failed';
                        throw new \RuntimeException($error);
                    }

                    // Log progress
                    if (! $this->wantsJson()) {
                        $this->output->write("\r  Status: {$status}...");
                    }
                }
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Connection error - Caddy might be reloading, continue waiting
            }

            sleep(5);
        }

        throw new \RuntimeException('Provisioning timed out after 10 minutes');
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
