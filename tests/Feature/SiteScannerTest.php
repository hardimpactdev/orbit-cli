<?php

use App\Services\ConfigManager;
use App\Services\SiteScanner;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
});

it('scans directories and returns sites', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir.'/project1', 0755, true);
    mkdir($tempDir.'/project2', 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);

    $scanner = new SiteScanner($this->configManager);
    $sites = $scanner->scan();

    expect($sites)->toHaveCount(2);
    expect($sites[0]['name'])->toBe('project1');
    expect($sites[0]['domain'])->toBe('project1.test');
    expect($sites[0]['php_version'])->toBe('8.3');
    expect($sites[1]['name'])->toBe('project2');

    // Cleanup
    rmdir($tempDir.'/project1');
    rmdir($tempDir.'/project2');
    rmdir($tempDir);
});

it('respects php-version file', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir.'/myproject', 0755, true);
    file_put_contents($tempDir.'/myproject/.php-version', '8.4');

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);

    $scanner = new SiteScanner($this->configManager);
    $sites = $scanner->scan();

    expect($sites)->toHaveCount(1);
    expect($sites[0]['php_version'])->toBe('8.4');
    expect($sites[0]['has_custom_php'])->toBeTrue();

    // Cleanup
    unlink($tempDir.'/myproject/.php-version');
    rmdir($tempDir.'/myproject');
    rmdir($tempDir);
});

it('respects site overrides from config', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir.'/myproject', 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([
        'myproject' => ['php_version' => '8.4'],
    ]);

    $scanner = new SiteScanner($this->configManager);
    $sites = $scanner->scan();

    expect($sites)->toHaveCount(1);
    expect($sites[0]['php_version'])->toBe('8.4');
    expect($sites[0]['has_custom_php'])->toBeTrue();

    // Cleanup
    rmdir($tempDir.'/myproject');
    rmdir($tempDir);
});

it('finds a site by name', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir.'/myproject', 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);

    $scanner = new SiteScanner($this->configManager);
    $site = $scanner->findSite('myproject');

    expect($site)->not->toBeNull();
    expect($site['name'])->toBe('myproject');

    // Cleanup
    rmdir($tempDir.'/myproject');
    rmdir($tempDir);
});

it('returns null for non-existent site', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);

    $scanner = new SiteScanner($this->configManager);
    $site = $scanner->findSite('nonexistent');

    expect($site)->toBeNull();

    // Cleanup
    rmdir($tempDir);
});

it('skips invalid directories', function () {
    $this->configManager->shouldReceive('getPaths')->andReturn(['/nonexistent/path']);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);

    $scanner = new SiteScanner($this->configManager);
    $sites = $scanner->scan();

    expect($sites)->toBeEmpty();
});
