<?php

use App\Services\ConfigManager;
use App\Services\SiteScanner;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->siteScanner = Mockery::mock(SiteScanner::class);

    $this->app->instance(ConfigManager::class, $this->configManager);
    $this->app->instance(SiteScanner::class, $this->siteScanner);
});

it('lists all sites', function () {
    $this->siteScanner->shouldReceive('scan')->andReturn([
        ['name' => 'mysite', 'domain' => 'mysite.test', 'path' => '/path/to/mysite', 'php_version' => '8.3', 'has_custom_php' => false],
        ['name' => 'another', 'domain' => 'another.test', 'path' => '/path/to/another', 'php_version' => '8.4', 'has_custom_php' => true],
    ]);
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('sites')
        ->expectsOutputToContain('mysite.test')
        ->expectsOutputToContain('another.test')
        ->assertExitCode(0);
});

it('shows warning when no sites found', function () {
    $this->siteScanner->shouldReceive('scan')->andReturn([]);
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('sites')
        ->expectsOutputToContain('No sites found')
        ->assertExitCode(0);
});

it('outputs json when --json flag is used', function () {
    $this->siteScanner->shouldReceive('scan')->andReturn([
        ['name' => 'mysite', 'domain' => 'mysite.test', 'path' => '/path/to/mysite', 'php_version' => '8.3', 'has_custom_php' => false],
    ]);
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('sites --json')
        ->assertExitCode(0);
});
