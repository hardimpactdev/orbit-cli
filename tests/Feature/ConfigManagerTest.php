<?php

use App\Services\ConfigManager;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/launchpad-config-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    // Store original HOME and set test HOME (ConfigManager uses getenv)
    $this->originalHome = getenv('HOME');
    putenv("HOME={$this->tempDir}");
    $_SERVER['HOME'] = $this->tempDir;
});

afterEach(function () {
    // Restore original HOME
    putenv("HOME={$this->originalHome}");
    $_SERVER['HOME'] = $this->originalHome;

    // Cleanup
    if (File::isDirectory($this->tempDir.'/.config/launchpad')) {
        File::deleteDirectory($this->tempDir.'/.config/launchpad');
    }
    if (File::isDirectory($this->tempDir.'/.config')) {
        File::deleteDirectory($this->tempDir.'/.config');
    }
    if (File::isDirectory($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('returns default values when config does not exist', function () {
    $configManager = new ConfigManager;

    expect($configManager->getDefaultPhpVersion())->toBe('8.3');
    expect($configManager->getTld())->toBe('test');
    expect($configManager->getHostIp())->toBe('127.0.0.1');
    expect($configManager->getPaths())->toBe([]);
});

it('loads config from file', function () {
    $configPath = $this->tempDir.'/.config/launchpad';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'default_php_version' => '8.4',
        'tld' => 'local',
        'paths' => ['/home/user/projects'],
    ]));

    $configManager = new ConfigManager;

    expect($configManager->getDefaultPhpVersion())->toBe('8.4');
    expect($configManager->getTld())->toBe('local');
    expect($configManager->getPaths())->toBe(['/home/user/projects']);
});

it('gets and sets values', function () {
    $configPath = $this->tempDir.'/.config/launchpad';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([]));

    $configManager = new ConfigManager;
    $configManager->set('custom_key', 'custom_value');

    expect($configManager->get('custom_key'))->toBe('custom_value');
});

it('manages site php version overrides', function () {
    $configPath = $this->tempDir.'/.config/launchpad';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([]));

    $configManager = new ConfigManager;

    $configManager->setSitePhpVersion('mysite', '8.4');
    expect($configManager->getSitePhpVersion('mysite'))->toBe('8.4');

    $configManager->removeSiteOverride('mysite');
    expect($configManager->getSitePhpVersion('mysite'))->toBeNull();
});

it('returns site overrides', function () {
    $configPath = $this->tempDir.'/.config/launchpad';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'sites' => [
            'site1' => ['php_version' => '8.4'],
            'site2' => ['php_version' => '8.3'],
        ],
    ]));

    $configManager = new ConfigManager;

    expect($configManager->getSiteOverrides())->toBe([
        'site1' => ['php_version' => '8.4'],
        'site2' => ['php_version' => '8.3'],
    ]);
});

it('checks enabled services', function () {
    $configPath = $this->tempDir.'/.config/launchpad';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'services' => [
            'postgres' => ['enabled' => true],
            'redis' => ['enabled' => false],
            'mailpit' => ['enabled' => true],
        ],
    ]));

    $configManager = new ConfigManager;

    expect($configManager->isServiceEnabled('postgres'))->toBeTrue();
    expect($configManager->isServiceEnabled('redis'))->toBeFalse();
    expect($configManager->getEnabledServices())->toBe(['postgres', 'mailpit']);
});
