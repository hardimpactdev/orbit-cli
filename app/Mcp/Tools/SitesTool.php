<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\ConfigManager;
use App\Services\SiteScanner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class SitesTool extends Tool
{
    protected string $name = 'launchpad_sites';

    protected string $description = 'List all registered sites with their domains, paths, PHP versions, and configuration details';

    public function __construct(
        protected ConfigManager $configManager,
        protected SiteScanner $siteScanner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $projects = $this->siteScanner->scanSites();

        $sites = array_map(fn ($project) => [
            'name' => $project['name'],
            'domain' => $project['domain'] ?? null,
            'path' => $project['path'],
            'php_version' => $project['php_version'],
            'has_custom_php' => $project['php_version'] !== $defaultPhp,
            'secure' => $project['secure'] ?? true,
        ], $projects);

        return Response::structured([
            'sites' => $sites,
            'default_php_version' => $defaultPhp,
            'sites_count' => count($sites),
        ]);
    }
}
