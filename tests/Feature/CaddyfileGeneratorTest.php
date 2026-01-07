<?php

use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\SiteScanner;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/launchpad-caddy-test-'.uniqid();
    mkdir($this->tempDir.'/caddy', 0755, true);
    mkdir($this->tempDir.'/php', 0755, true);

    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->siteScanner = Mockery::mock(SiteScanner::class);

    $this->configManager->shouldReceive('getConfigPath')->andReturn($this->tempDir);
    $this->configManager->shouldReceive('isServiceEnabled')->andReturn(false);
    $this->configManager->shouldReceive('get')->andReturnUsing(function ($key, $default = null) {
        return $default;
    });
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

it('generates caddyfile with sites', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn(['~/Projects']);

    $this->siteScanner->shouldReceive('scanSites')->andReturn([
        [
            'name' => 'mysite',
            'domain' => 'mysite.test',
            'path' => '/home/user/Projects/mysite',
            'php_version' => '8.3',
            'has_custom_php' => false,
        ],
        [
            'name' => 'another',
            'domain' => 'another.test',
            'path' => '/home/user/Projects/another',
            'php_version' => '8.4',
            'has_custom_php' => true,
        ],
    ]);

    $generator = new CaddyfileGenerator($this->configManager, $this->siteScanner);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    expect($caddyfile)->toContain('local_certs');
    expect($caddyfile)->toContain('mysite.test');
    expect($caddyfile)->toContain('another.test');
    expect($caddyfile)->toContain('launchpad-php-83');
    expect($caddyfile)->toContain('launchpad-php-84');
});

it('generates php caddyfile with document roots', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn(['~/Projects']);

    $this->siteScanner->shouldReceive('scanSites')->andReturn([
        [
            'name' => 'mysite',
            'domain' => 'mysite.test',
            'path' => '/home/user/Projects/mysite',
            'php_version' => '8.3',
            'has_custom_php' => false,
        ],
    ]);

    $generator = new CaddyfileGenerator($this->configManager, $this->siteScanner);
    $generator->generate();

    $phpCaddyfile = File::get($this->tempDir.'/php/Caddyfile');

    expect($phpCaddyfile)->toContain('frankenphp');
    expect($phpCaddyfile)->toContain('mysite.test:8080');
    expect($phpCaddyfile)->toContain('/public');
    expect($phpCaddyfile)->toContain('php_server');
});

it('generates empty caddyfile when no sites exist', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn([]);

    $this->siteScanner->shouldReceive('scanSites')->andReturn([]);

    $generator = new CaddyfileGenerator($this->configManager, $this->siteScanner);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    expect($caddyfile)->toContain('local_certs');
    expect($caddyfile)->not->toContain('.test');
});
