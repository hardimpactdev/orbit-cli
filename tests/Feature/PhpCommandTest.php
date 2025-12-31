<?php

use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\SiteScanner;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->siteScanner = Mockery::mock(SiteScanner::class);
    $this->caddyfileGenerator = Mockery::mock(CaddyfileGenerator::class);

    $this->app->instance(ConfigManager::class, $this->configManager);
    $this->app->instance(SiteScanner::class, $this->siteScanner);
    $this->app->instance(CaddyfileGenerator::class, $this->caddyfileGenerator);
});

it('sets php version for a site', function () {
    $this->siteScanner->shouldReceive('findSite')->with('mysite')->andReturn([
        'name' => 'mysite',
        'domain' => 'mysite.test',
        'path' => '/path/to/mysite',
        'php_version' => '8.3',
        'has_custom_php' => false,
    ]);
    $this->configManager->shouldReceive('setSitePhpVersion')->with('mysite', '8.4')->once();
    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->caddyfileGenerator->shouldReceive('reload')->andReturn(true);

    $this->artisan('php mysite 8.4')
        ->expectsOutputToContain('Set mysite to PHP 8.4')
        ->assertExitCode(0);
});

it('resets php version to default', function () {
    $this->siteScanner->shouldReceive('findSite')->with('mysite')->andReturn([
        'name' => 'mysite',
        'domain' => 'mysite.test',
        'path' => '/path/to/mysite',
        'php_version' => '8.4',
        'has_custom_php' => true,
    ]);
    $this->configManager->shouldReceive('removeSiteOverride')->with('mysite')->once();
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->caddyfileGenerator->shouldReceive('reload')->andReturn(true);

    $this->artisan('php mysite --reset')
        ->expectsOutputToContain('Reset mysite to default PHP version')
        ->assertExitCode(0);
});

it('fails when site not found', function () {
    $this->siteScanner->shouldReceive('findSite')->with('nonexistent')->andReturn(null);

    $this->artisan('php nonexistent 8.4')
        ->expectsOutputToContain("Site 'nonexistent' not found")
        ->assertExitCode(ExitCode::InvalidArguments->value);
});

it('fails with invalid php version', function () {
    $this->siteScanner->shouldReceive('findSite')->with('mysite')->andReturn([
        'name' => 'mysite',
        'domain' => 'mysite.test',
        'path' => '/path/to/mysite',
        'php_version' => '8.3',
        'has_custom_php' => false,
    ]);

    $this->artisan('php mysite 7.4')
        ->expectsOutputToContain('Invalid PHP version')
        ->assertExitCode(ExitCode::InvalidArguments->value);
});

it('outputs json when --json flag is used', function () {
    $this->siteScanner->shouldReceive('findSite')->with('mysite')->andReturn([
        'name' => 'mysite',
        'domain' => 'mysite.test',
        'path' => '/path/to/mysite',
        'php_version' => '8.3',
        'has_custom_php' => false,
    ]);
    $this->configManager->shouldReceive('setSitePhpVersion')->with('mysite', '8.4')->once();
    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->caddyfileGenerator->shouldReceive('reload')->andReturn(true);

    $this->artisan('php mysite 8.4 --json')
        ->assertExitCode(0);
});
