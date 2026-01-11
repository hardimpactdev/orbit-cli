<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\SiteScanner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class StatusTool extends Tool
{
    protected string $name = 'launchpad_status';

    protected string $description = 'Get Launchpad service status including running containers, sites count, TLD, and default PHP version';

    public function __construct(
        protected DockerManager $dockerManager,
        protected ConfigManager $configManager,
        protected SiteScanner $siteScanner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $services = [
            'dns' => [
                'status' => $this->dockerManager->isRunning('launchpad-dns') ? 'running' : 'stopped',
                'container' => 'launchpad-dns',
            ],
            'php-83' => [
                'status' => $this->dockerManager->isRunning('launchpad-php-83') ? 'running' : 'stopped',
                'container' => 'launchpad-php-83',
            ],
            'php-84' => [
                'status' => $this->dockerManager->isRunning('launchpad-php-84') ? 'running' : 'stopped',
                'container' => 'launchpad-php-84',
            ],
            'php-85' => [
                'status' => $this->dockerManager->isRunning('launchpad-php-85') ? 'running' : 'stopped',
                'container' => 'launchpad-php-85',
            ],
            'caddy' => [
                'status' => $this->dockerManager->isRunning('launchpad-caddy') ? 'running' : 'stopped',
                'container' => 'launchpad-caddy',
            ],
            'postgres' => [
                'status' => $this->dockerManager->isRunning('launchpad-postgres') ? 'running' : 'stopped',
                'container' => 'launchpad-postgres',
            ],
            'redis' => [
                'status' => $this->dockerManager->isRunning('launchpad-redis') ? 'running' : 'stopped',
                'container' => 'launchpad-redis',
            ],
            'mailpit' => [
                'status' => $this->dockerManager->isRunning('launchpad-mailpit') ? 'running' : 'stopped',
                'container' => 'launchpad-mailpit',
            ],
        ];

        // Add optional services if enabled
        if ($this->configManager->isServiceEnabled('reverb')) {
            $services['reverb'] = [
                'status' => $this->dockerManager->isRunning('launchpad-reverb') ? 'running' : 'stopped',
                'container' => 'launchpad-reverb',
            ];
        }

        if ($this->configManager->isServiceEnabled('horizon')) {
            $services['horizon'] = [
                'status' => $this->dockerManager->isRunning('launchpad-horizon') ? 'running' : 'stopped',
                'container' => 'launchpad-horizon',
            ];
        }

        $runningCount = count(array_filter($services, fn ($s) => $s['status'] === 'running'));
        $totalCount = count($services);
        $running = $runningCount === $totalCount;

        $sites = $this->siteScanner->scanSites();

        return Response::structured([
            'running' => $running,
            'services' => $services,
            'services_running' => $runningCount,
            'services_total' => $totalCount,
            'sites_count' => count($sites),
            'config_path' => $this->configManager->getConfigPath(),
            'tld' => $this->configManager->getTld(),
            'default_php_version' => $this->configManager->getDefaultPhpVersion(),
        ]);
    }
}
