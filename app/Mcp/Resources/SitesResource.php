<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Services\ConfigManager;
use App\Services\DatabaseService;
use App\Services\SiteScanner;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class SitesResource extends Resource
{
    protected string $uri = 'launchpad://sites';

    protected string $mimeType = 'application/json';

    public function __construct(
        protected ConfigManager $configManager,
        protected DatabaseService $databaseService,
        protected SiteScanner $siteScanner
    ) {
        //
    }

    public function name(): string
    {
        return 'sites';
    }

    public function title(): string
    {
        return 'Sites';
    }

    public function description(): string
    {
        return 'All registered sites with their domains, paths, PHP versions, and custom settings.';
    }

    public function handle(Request $request): Response
    {
        $sites = $this->siteScanner->scanSites();
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $tld = $this->configManager->getTld();

        $formattedSites = array_values(array_map(fn ($site) => [
            'name' => $site['name'],
            'display_name' => $site['display_name'] ?? ucwords(str_replace(['-', '_'], ' ', $site['name'])),
            'github_repo' => $site['github_repo'] ?? null,
            'project_type' => $site['project_type'] ?? 'unknown',
            'domain' => $site['domain'] ?? null,
            'path' => $site['path'],
            'php_version' => $site['php_version'],
            'has_custom_php' => $site['has_custom_php'],
            'secure' => true,
        ], $sites));

        return Response::json([
            'sites' => $formattedSites,
            'summary' => [
                'total' => count($sites),
                'with_custom_php' => count(array_filter($sites, fn ($s) => $s['has_custom_php'])),
                'default_php_version' => $defaultPhp,
                'tld' => $tld,
            ],
        ]);
    }
}
