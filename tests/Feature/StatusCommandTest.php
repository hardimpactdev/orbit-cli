<?php

use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\SiteScanner;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->dockerManager = Mockery::mock(DockerManager::class);
    $this->siteScanner = Mockery::mock(SiteScanner::class);

    $this->app->instance(ConfigManager::class, $this->configManager);
    $this->app->instance(DockerManager::class, $this->dockerManager);
    $this->app->instance(SiteScanner::class, $this->siteScanner);
});

it('shows status with all services running', function () {
    $this->dockerManager->shouldReceive('isRunning')->andReturn(true);
    $this->siteScanner->shouldReceive('scan')->andReturn([
        ['name' => 'mysite', 'domain' => 'mysite.test', 'path' => '/path/to/mysite', 'php_version' => '8.3', 'has_custom_php' => false],
    ]);
    $this->configManager->shouldReceive('getConfigPath')->andReturn('/home/user/.config/launchpad');
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('status')
        ->expectsOutputToContain('Launchpad is running')
        ->assertExitCode(0);
});

it('shows status with all services stopped', function () {
    $this->dockerManager->shouldReceive('isRunning')->andReturn(false);
    $this->siteScanner->shouldReceive('scan')->andReturn([]);
    $this->configManager->shouldReceive('getConfigPath')->andReturn('/home/user/.config/launchpad');
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('status')
        ->expectsOutputToContain('Launchpad is stopped')
        ->assertExitCode(0);
});

it('outputs json when --json flag is used', function () {
    $this->dockerManager->shouldReceive('isRunning')->andReturn(true);
    $this->siteScanner->shouldReceive('scan')->andReturn([]);
    $this->configManager->shouldReceive('getConfigPath')->andReturn('/home/user/.config/launchpad');
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('status --json')
        ->assertExitCode(0);
});
