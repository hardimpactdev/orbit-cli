<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\ConfigManager;
use App\Services\DatabaseService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class PhpTool extends Tool
{
    protected string $description = 'Get or set PHP version for a site';

    public function __construct(
        protected DatabaseService $databaseService,
        protected ConfigManager $configManager,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->required()->description('The site name'),
            'action' => $schema->string()->enum(['get', 'set', 'reset'])->required()->description('Action to perform: get current version, set new version, or reset to default'),
            'version' => $schema->string()->enum(['8.3', '8.4', '8.5'])->description('PHP version to set (required for "set" action)'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $site = $request->get('site');
        $action = $request->get('action');
        $version = $request->get('version');

        // Validate required parameters
        if (! $site || ! $action) {
            return Response::error('Missing required parameters: site and action are required');
        }

        // Get the site path - we need this for set/reset operations
        $sitePath = $this->getSitePath($site);

        switch ($action) {
            case 'get':
                return $this->getPhpVersion($site);

            case 'set':
                if (! $version) {
                    return Response::error('Version parameter is required for "set" action');
                }

                if (! in_array($version, ['8.3', '8.4', '8.5'])) {
                    return Response::error('Invalid PHP version. Must be one of: 8.3, 8.4, 8.5');
                }

                if (! $sitePath) {
                    return Response::error("Site not found: {$site}");
                }

                return $this->setPhpVersion($site, $sitePath, $version);

            case 'reset':
                return $this->resetPhpVersion($site);

            default:
                return Response::error('Invalid action. Must be one of: get, set, reset');
        }
    }

    protected function getPhpVersion(string $site): Response|ResponseFactory
    {
        $phpVersion = $this->databaseService->getPhpVersion($site);
        $defaultVersion = $this->configManager->getDefaultPhpVersion();

        if ($phpVersion === null) {
            return Response::structured([
                'site' => $site,
                'php_version' => $defaultVersion,
                'is_custom' => false,
                'default_version' => $defaultVersion,
            ]);
        }

        return Response::structured([
            'site' => $site,
            'php_version' => $phpVersion,
            'is_custom' => true,
            'default_version' => $defaultVersion,
        ]);
    }

    protected function setPhpVersion(string $site, string $path, string $version): Response|ResponseFactory
    {
        $this->databaseService->setProjectPhpVersion($site, $path, $version);

        return Response::structured([
            'success' => true,
            'site' => $site,
            'php_version' => $version,
            'message' => "PHP version set to {$version} for {$site}",
        ]);
    }

    protected function resetPhpVersion(string $site): Response|ResponseFactory
    {
        $this->databaseService->removeProjectOverride($site);
        $defaultVersion = $this->configManager->getDefaultPhpVersion();

        return Response::structured([
            'success' => true,
            'site' => $site,
            'php_version' => $defaultVersion,
            'message' => "PHP version reset to default ({$defaultVersion}) for {$site}",
        ]);
    }

    protected function getSitePath(string $site): ?string
    {
        // Try to get from database first
        $override = $this->databaseService->getProjectOverride($site);
        if ($override && isset($override['path'])) {
            return $override['path'];
        }

        // Try to find in scan paths
        $paths = $this->configManager->getPaths();
        foreach ($paths as $basePath) {
            $sitePath = rtrim($basePath, '/').'/'.$site;
            if (is_dir($sitePath)) {
                return $sitePath;
            }
        }

        return null;
    }
}
