<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\DatabaseService;
use App\Services\SiteScanner;
use LaravelZero\Framework\Commands\Command;

class PhpCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'php
        {site : The site name to configure}
        {version? : The PHP version to use (8.3, 8.4)}
        {--reset : Reset to default PHP version}
        {--json : Output as JSON}';

    protected $description = 'Set PHP version for a site';

    protected array $validVersions = ['8.3', '8.4'];

    public function handle(
        ConfigManager $configManager,
        SiteScanner $siteScanner,
        CaddyfileGenerator $caddyfileGenerator,
        DatabaseService $databaseService
    ): int {
        $site = $this->argument('site');
        $version = $this->argument('version');
        $reset = $this->option('reset');

        // Verify site exists (now includes all directories)
        $siteInfo = $siteScanner->findSite($site);
        if (! $siteInfo) {
            if ($this->wantsJson()) {
                return $this->outputJsonError(
                    "Site '{$site}' not found.",
                    ExitCode::InvalidArguments->value
                );
            }
            $this->error("Site '{$site}' not found.");

            return ExitCode::InvalidArguments->value;
        }

        if ($reset) {
            // Remove from database
            $databaseService->removeProjectOverride($site);
            // Also remove from config (legacy cleanup)
            $configManager->removeSiteOverride($site);

            $newVersion = $configManager->getDefaultPhpVersion();

            // Only regenerate Caddyfile if site has public folder
            if ($siteInfo['has_public_folder']) {
                $this->regenerateAndReload($caddyfileGenerator);
            }

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'site' => $site,
                    'php_version' => $newVersion,
                    'action' => 'reset',
                    'reloaded' => $siteInfo['has_public_folder'],
                ]);
            }

            $this->info("Reset {$site} to default PHP version ({$newVersion})");

            return self::SUCCESS;
        }

        if (! $version) {
            if ($this->wantsJson()) {
                return $this->outputJsonError(
                    'Please provide a PHP version or use --reset',
                    ExitCode::InvalidArguments->value
                );
            }
            $this->error('Please provide a PHP version or use --reset');

            return ExitCode::InvalidArguments->value;
        }

        if (! in_array($version, $this->validVersions)) {
            $message = 'Invalid PHP version. Valid versions: '.implode(', ', $this->validVersions);
            if ($this->wantsJson()) {
                return $this->outputJsonError($message, ExitCode::InvalidArguments->value, [
                    'valid_versions' => $this->validVersions,
                ]);
            }
            $this->error($message);

            return ExitCode::InvalidArguments->value;
        }

        // Save to database (new way)
        $databaseService->setProjectPhpVersion($site, $siteInfo['path'], $version);

        // Only regenerate Caddyfile if site has public folder
        $reloaded = false;
        if ($siteInfo['has_public_folder']) {
            $reloaded = $this->regenerateAndReload($caddyfileGenerator);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'site' => $site,
                'php_version' => $version,
                'action' => 'set',
                'reloaded' => $reloaded,
            ]);
        }

        $this->info("Set {$site} to PHP {$version}");

        if ($reloaded) {
            $this->info('Caddy reloaded');
        } elseif ($siteInfo['has_public_folder']) {
            $this->warn('Could not reload Caddy. You may need to restart Launchpad.');
        }

        return self::SUCCESS;
    }

    private function regenerateAndReload(CaddyfileGenerator $caddyfileGenerator): bool
    {
        if (! $this->wantsJson()) {
            $this->task('Regenerating Caddyfile', function () use ($caddyfileGenerator) {
                $caddyfileGenerator->generate();

                return true;
            });
        } else {
            $caddyfileGenerator->generate();
        }

        return $caddyfileGenerator->reload();
    }
}
