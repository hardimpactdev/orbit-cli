<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Services\ConfigManager;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class ConfigResource extends Resource
{
    protected string $uri = 'launchpad://config';

    protected string $mimeType = 'application/json';

    public function __construct(protected ConfigManager $configManager)
    {
        //
    }

    public function name(): string
    {
        return 'config';
    }

    public function title(): string
    {
        return 'Configuration';
    }

    public function description(): string
    {
        return 'Current Launchpad configuration including TLD, default PHP version, paths, and enabled services.';
    }

    public function handle(Request $request): Response
    {
        $enabledServices = $this->configManager->getEnabledServices();
        $reverbConfig = $this->configManager->getReverbConfig();

        return Response::json([
            'tld' => $this->configManager->getTld(),
            'host_ip' => $this->configManager->getHostIp(),
            'default_php_version' => $this->configManager->getDefaultPhpVersion(),
            'paths' => $this->configManager->getPaths(),
            'config_path' => $this->configManager->getConfigPath(),
            'web_app_path' => $this->configManager->getWebAppPath(),
            'enabled_services' => $enabledServices,
            'reverb' => $reverbConfig,
        ]);
    }
}
