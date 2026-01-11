<?php

use App\Services\DatabaseService;

beforeEach(function () {
    $this->testDir = sys_get_temp_dir().'/launchpad-db-tests-'.uniqid();
    @mkdir($this->testDir, 0755, true);

    $this->dbPath = $this->testDir.'/test.sqlite';
    $this->db = new DatabaseService($this->dbPath);
});

afterEach(function () {
    // Clean up test directory
    if (is_dir($this->testDir)) {
        $files = array_diff(scandir($this->testDir), ['.', '..']);
        foreach ($files as $file) {
            unlink("{$this->testDir}/{$file}");
        }
        rmdir($this->testDir);
    }
});

it('creates database file', function () {
    expect(file_exists($this->dbPath))->toBeTrue();
});

it('returns correct database path', function () {
    expect($this->db->getDbPath())->toBe($this->dbPath);
});

it('stores and retrieves project PHP version', function () {
    $this->db->setProjectPhpVersion('my-project', '/path/to/project', '8.4');

    $version = $this->db->getPhpVersion('my-project');
    expect($version)->toBe('8.4');
});

it('returns null for non-existent project', function () {
    $version = $this->db->getPhpVersion('non-existent');
    expect($version)->toBeNull();
});

it('updates existing project', function () {
    $this->db->setProjectPhpVersion('my-project', '/path/to/project', '8.3');
    $this->db->setProjectPhpVersion('my-project', '/path/to/project', '8.4');

    $version = $this->db->getPhpVersion('my-project');
    expect($version)->toBe('8.4');
});

it('retrieves full project override', function () {
    $this->db->setProjectPhpVersion('my-project', '/path/to/project', '8.4');

    $override = $this->db->getProjectOverride('my-project');

    expect($override)->not->toBeNull();
    expect($override['slug'])->toBe('my-project');
    expect($override['path'])->toBe('/path/to/project');
    expect($override['php_version'])->toBe('8.4');
});

it('removes project override', function () {
    $this->db->setProjectPhpVersion('my-project', '/path/to/project', '8.4');
    $this->db->removeProjectOverride('my-project');

    $override = $this->db->getProjectOverride('my-project');
    expect($override)->toBeNull();
});

it('returns all overrides', function () {
    $this->db->setProjectPhpVersion('project-1', '/path/1', '8.3');
    $this->db->setProjectPhpVersion('project-2', '/path/2', '8.4');
    $this->db->setProjectPhpVersion('project-3', '/path/3', null);

    $overrides = $this->db->getAllOverrides();

    // Only projects with php_version set are returned
    expect($overrides)->toHaveCount(2);
});

it('truncates all data', function () {
    $this->db->setProjectPhpVersion('project-1', '/path/1', '8.3');
    $this->db->setProjectPhpVersion('project-2', '/path/2', '8.4');

    $this->db->truncate();

    $overrides = $this->db->getAllOverrides();
    expect($overrides)->toHaveCount(0);
});

it('uses environment variable for database path', function () {
    $testPath = $this->testDir.'/env-test.sqlite';
    putenv("LAUNCHPAD_TEST_DB={$testPath}");

    $db = new DatabaseService;

    expect($db->getDbPath())->toBe($testPath);

    // Clean up
    putenv('LAUNCHPAD_TEST_DB');
});
