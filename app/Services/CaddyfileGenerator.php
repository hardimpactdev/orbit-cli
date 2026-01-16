<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class CaddyfileGenerator
{
    protected string $caddyfilePath;

    public function __construct(
        protected ConfigManager $configManager,
        protected SiteScanner $siteScanner,
        protected ?PhpManager $phpManager = null,
        protected ?WorktreeService $worktreeService = null
    ) {
        $this->caddyfilePath = $this->configManager->getConfigPath().'/caddy/Caddyfile';
    }

    /**
     * Get the PHP-FPM socket path for a version.
     */
    protected function getSocketPath(string $version): string
    {
        if ($this->phpManager === null) {
            $this->phpManager = app(PhpManager::class);
        }

        return $this->phpManager->getSocketPath($version);
    }

    public function generate(): void
    {
        $this->generateCaddyfile();
    }

    protected function generateCaddyfile(): void
    {
        $sites = $this->siteScanner->scanSites();
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $tld = $this->configManager->get('tld') ?: 'test';
        $defaultSocket = $this->getSocketPath($defaultPhp);

        $caddyfile = '{
    local_certs
}

';

        // Add orbit management UI site
        $webAppPath = $this->configManager->getWebAppPath();
        if (is_dir($webAppPath)) {
            $caddyfile .= "orbit.{$tld} {
    tls internal
    root * {$webAppPath}/public
    encode gzip
    php_fastcgi unix/{$defaultSocket}
    file_server
}

";
        }

        // Generate entry for each site
        foreach ($sites as $site) {
            $socket = $site['has_custom_php']
                ? $this->getSocketPath($site['php_version'])
                : $defaultSocket;
            $root = $site['path'].'/public';

            $caddyfile .= "{$site['domain']} {
    tls internal
    root * {$root}
    encode gzip

    # Vite dev server proxy (header_up bypasses Vite allowedHosts bug)
    @vite path /@vite/* /@id/* /@fs/* /resources/* /node_modules/* /lang/* /__devtools__/*
    reverse_proxy @vite localhost:5173 {
        header_up Host localhost
    }

    @ws {
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @ws localhost:5173 {
        header_up Host localhost
    }

    php_fastcgi unix/{$socket}
    file_server
}

";
        }

        // Generate entries for worktrees
        $worktrees = $this->getWorktreesForCaddy();
        foreach ($worktrees as $worktree) {
            $socket = $this->getSocketPath($worktree['php_version']);
            $root = $worktree['path'].'/public';

            $caddyfile .= "{$worktree['domain']} {
    tls internal
    root * {$root}
    encode gzip
    php_fastcgi unix/{$socket}
    file_server
}

";
        }

        // Add Reverb WebSocket service if enabled
        if ($this->configManager->isServiceEnabled('reverb')) {
            $caddyfile .= "reverb.{$tld} {
    tls internal
    @websocket {
        path /app /app/*
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @websocket localhost:6001
    reverse_proxy localhost:6001
}

";
        }

        File::put($this->caddyfilePath, $caddyfile);
    }

    public function reload(): bool
    {
        if ($this->phpManager === null) {
            $this->phpManager = app(PhpManager::class);
        }

        return $this->phpManager->getAdapter()->reloadCaddy();
    }

    public function reloadPhp(): bool
    {
        if ($this->phpManager === null) {
            $this->phpManager = app(PhpManager::class);
        }

        $defaultVersion = $this->configManager->get('default_php_version', '8.4');

        return $this->phpManager->getAdapter()->restartPhpFpm($defaultVersion);
    }

    protected function getWorktreesForCaddy(): array
    {
        if ($this->worktreeService === null) {
            $this->worktreeService = app(WorktreeService::class);
        }

        try {
            return $this->worktreeService->getLinkedWorktreesForCaddy();
        } catch (\Exception) {
            return [];
        }
    }
}
