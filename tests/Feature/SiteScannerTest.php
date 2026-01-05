<?php

use App\Services\ConfigManager;
use App\Services\SiteScanner;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
});

it('scans directories and returns sites', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir.'/project1/public', 0755, true);
    mkdir($tempDir.'/project2/public', 0755, true);

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
    rmdir($tempDir.'/project1/public');
    rmdir($tempDir.'/project1');
    rmdir($tempDir.'/project2/public');
    rmdir($tempDir.'/project2');
    rmdir($tempDir);
});

it('respects php-version file', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir.'/myproject/public', 0755, true);
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
    rmdir($tempDir.'/myproject/public');
    rmdir($tempDir.'/myproject');
    rmdir($tempDir);
});

it('respects site overrides from config', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir.'/myproject/public', 0755, true);

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
    rmdir($tempDir.'/myproject/public');
    rmdir($tempDir.'/myproject');
    rmdir($tempDir);
});

it('finds a site by name', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    mkdir($tempDir.'/myproject/public', 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);

    $scanner = new SiteScanner($this->configManager);
    $site = $scanner->findSite('myproject');

    expect($site)->not->toBeNull();
    expect($site['name'])->toBe('myproject');

    // Cleanup
    rmdir($tempDir.'/myproject/public');
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

it('respects custom path overrides from config', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    $nestedDir = $tempDir.'/parent/nested/myproject';
    mkdir($nestedDir.'/public', 0755, true);
    mkdir($tempDir.'/myproject/public', 0755, true); // Regular project with same name

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([
        'myproject' => ['path' => $nestedDir],
    ]);

    $scanner = new SiteScanner($this->configManager);
    $sites = $scanner->scan();

    // Should find myproject pointing to nested path (custom takes precedence)
    $myprojectSite = collect($sites)->firstWhere('name', 'myproject');
    expect($myprojectSite)->not->toBeNull();
    expect($myprojectSite['path'])->toBe($nestedDir);

    // Cleanup
    rmdir($tempDir.'/myproject/public');
    rmdir($tempDir.'/myproject');
    rmdir($nestedDir.'/public');
    rmdir($nestedDir);
    rmdir($tempDir.'/parent/nested');
    rmdir($tempDir.'/parent');
    rmdir($tempDir);
});

it('includes custom path sites even if not in scanned paths', function () {
    $tempDir = sys_get_temp_dir().'/launchpad-test-'.uniqid();
    $customDir = sys_get_temp_dir().'/launchpad-custom-'.uniqid();
    mkdir($tempDir, 0755, true);
    mkdir($customDir.'/public', 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([
        'customsite' => ['path' => $customDir],
    ]);

    $scanner = new SiteScanner($this->configManager);
    $sites = $scanner->scan();

    expect($sites)->toHaveCount(1);
    expect($sites[0]['name'])->toBe('customsite');
    expect($sites[0]['path'])->toBe($customDir);
    expect($sites[0]['domain'])->toBe('customsite.test');

    // Cleanup
    rmdir($customDir.'/public');
    rmdir($customDir);
    rmdir($tempDir);
});
