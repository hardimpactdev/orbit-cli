<?php

use App\Services\ProvisionLogger;

beforeEach(function () {
    $this->logDir = sys_get_temp_dir().'/launchpad-tests/logs';
    @mkdir($this->logDir, 0755, true);
});

afterEach(function () {
    deleteDirectory(sys_get_temp_dir().'/launchpad-tests');
});

it('creates log file when slug is provided', function () {
    // Override HOME for this test
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = sys_get_temp_dir().'/launchpad-tests';

    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->info('Test message');

    $logFile = sys_get_temp_dir().'/launchpad-tests/.config/launchpad/logs/provision/test-project.log';
    expect(file_exists($logFile))->toBeTrue();
    expect(file_get_contents($logFile))->toContain('Test message');

    if ($originalHome !== null) {
        $_SERVER['HOME'] = $originalHome;
    }
});

it('logs info messages', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = sys_get_temp_dir().'/launchpad-tests';

    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->info('Info message');

    $logFile = sys_get_temp_dir().'/launchpad-tests/.config/launchpad/logs/provision/test-project.log';
    $content = file_get_contents($logFile);
    expect($content)->toContain('Info message');

    if ($originalHome !== null) {
        $_SERVER['HOME'] = $originalHome;
    }
});

it('logs warning messages with prefix', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = sys_get_temp_dir().'/launchpad-tests';

    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->warn('Warning message');

    $logFile = sys_get_temp_dir().'/launchpad-tests/.config/launchpad/logs/provision/test-project.log';
    $content = file_get_contents($logFile);
    expect($content)->toContain('WARNING: Warning message');

    if ($originalHome !== null) {
        $_SERVER['HOME'] = $originalHome;
    }
});

it('logs error messages with prefix', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = sys_get_temp_dir().'/launchpad-tests';

    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->error('Error message');

    $logFile = sys_get_temp_dir().'/launchpad-tests/.config/launchpad/logs/provision/test-project.log';
    $content = file_get_contents($logFile);
    expect($content)->toContain('ERROR: Error message');

    if ($originalHome !== null) {
        $_SERVER['HOME'] = $originalHome;
    }
});

it('logs status broadcasts', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = sys_get_temp_dir().'/launchpad-tests';

    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->broadcast('installing_composer');

    $logFile = sys_get_temp_dir().'/launchpad-tests/.config/launchpad/logs/provision/test-project.log';
    $content = file_get_contents($logFile);
    expect($content)->toContain('Status: installing_composer');

    if ($originalHome !== null) {
        $_SERVER['HOME'] = $originalHome;
    }
});

it('logs broadcast with error', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = sys_get_temp_dir().'/launchpad-tests';

    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->broadcast('failed', 'Something went wrong');

    $logFile = sys_get_temp_dir().'/launchpad-tests/.config/launchpad/logs/provision/test-project.log';
    $content = file_get_contents($logFile);
    expect($content)->toContain('Status: failed - Error: Something went wrong');

    if ($originalHome !== null) {
        $_SERVER['HOME'] = $originalHome;
    }
});
