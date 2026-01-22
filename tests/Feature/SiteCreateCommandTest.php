<?php

use App\Commands\SiteCreateCommand;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/orbit-project-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $this->configManager = Mockery::mock(ConfigManager::class)->makePartial();
    $this->configManager->shouldReceive('getPaths')->andReturn([$this->tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getConfigPath')->andReturn($this->tempDir.'/.config/orbit');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.4');
    $this->configManager->shouldReceive('get')->with('sequence.url', 'http://localhost:8000')->andReturn('http://localhost:8000');
    $this->configManager->shouldReceive('get')->with('reverb.url', '')->andReturn('');
    $this->configManager->shouldReceive('get')->with('paths', ['~/projects'])->andReturn([$this->tempDir]);
    $this->app->instance(ConfigManager::class, $this->configManager);

    // Set HOME to temp for ProvisionLogger
    $_SERVER['HOME'] = '/tmp';
    @mkdir('/tmp/.config/orbit/logs/provision', 0755, true);
});

afterEach(function () {
    \Illuminate\Support\Facades\File::deleteDirectory($this->tempDir);
    @unlink('/tmp/.config/orbit/logs/provision/test-site.log');
});

describe('site:create command', function () {
    it('runs site:create command with basic name', function () {
        // Mock the API response
        Http::fake([
            'https://orbit.test/api/sites' => Http::response([
                'success' => true,
                'slug' => 'test-site',
                'message' => 'Site creation queued',
            ]),
        ]);

        $this->artisan('site:create', ['name' => 'test-site', '--json' => true])
            ->assertExitCode(0);
    });

    it('rejects reserved name "orbit"', function () {
        $this->artisan('site:create', ['name' => 'orbit', '--json' => true])
            ->assertExitCode(1);
    });

    it('handles API errors gracefully', function () {
        Http::fake([
            'https://orbit.test/api/sites' => Http::response([
                'success' => false,
                'error' => 'Site already exists',
            ], 422),
        ]);

        $this->artisan('site:create', ['name' => 'existing-site', '--json' => true])
            ->assertExitCode(1);
    });

    it('handles network errors gracefully', function () {
        Http::fake([
            'https://orbit.test/api/sites' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        $this->artisan('site:create', ['name' => 'test-site', '--json' => true])
            ->assertExitCode(1);
    });
});

describe('option definitions', function () {
    /**
     * CRITICAL: Verify all options that the API expects are defined.
     * This ensures CLI options match the orbit-core API.
     */
    it('has --organization option defined (not --org)', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $definition = $command->getDefinition();

        // The API uses 'org' key, but CLI uses --organization for clarity
        expect($definition->hasOption('organization'))->toBeTrue();
        // Verify we don't have a confusingly-named --org option
        expect($definition->hasOption('org'))->toBeFalse();
    });

    it('has all expected options', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $definition = $command->getDefinition();

        // Options supported by the CLI that map to API fields
        $expectedOptions = [
            'template',
            'clone',
            'fork',
            'visibility',
            'organization',  // Maps to 'org' in API
            'php',           // Maps to 'php_version' in API
            'db-driver',     // Maps to 'db_driver' in API
            'session-driver',
            'cache-driver',
            'queue-driver',
            'wait',          // CLI-only: wait for provisioning completion
            'json',
        ];

        foreach ($expectedOptions as $option) {
            expect($definition->hasOption($option))
                ->toBeTrue("Missing option: --{$option}");
        }
    });

    it('has correct option defaults', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $definition = $command->getDefinition();

        expect($definition->getOption('visibility')->getDefault())->toBe('private');
        expect($definition->getOption('fork')->getDefault())->toBeFalse();
        expect($definition->getOption('wait')->getDefault())->toBeFalse();
    });
});

describe('API payload building', function () {
    it('sends correct payload for basic site creation', function () {
        Http::fake([
            'https://orbit.test/api/sites' => Http::response([
                'success' => true,
                'slug' => 'my-site',
            ]),
        ]);

        $this->artisan('site:create', ['name' => 'My Site', '--json' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://orbit.test/api/sites'
                && $request['name'] === 'My Site';
        });
    });

    it('sends clone URL as template with is_template=false', function () {
        Http::fake([
            'https://orbit.test/api/sites' => Http::response([
                'success' => true,
                'slug' => 'cloned-site',
            ]),
        ]);

        $this->artisan('site:create', [
            'name' => 'cloned-site',
            '--clone' => 'user/repo',
            '--json' => true,
        ]);

        Http::assertSent(function ($request) {
            return $request['template'] === 'user/repo'
                && $request['is_template'] === false;
        });
    });

    it('sends template with is_template=true', function () {
        Http::fake([
            'https://orbit.test/api/sites' => Http::response([
                'success' => true,
                'slug' => 'from-template',
            ]),
        ]);

        $this->artisan('site:create', [
            'name' => 'from-template',
            '--template' => 'owner/template-repo',
            '--json' => true,
        ]);

        Http::assertSent(function ($request) {
            return $request['template'] === 'owner/template-repo'
                && $request['is_template'] === true;
        });
    });

    it('normalizes git URLs to owner/repo format', function () {
        Http::fake([
            'https://orbit.test/api/sites' => Http::response([
                'success' => true,
                'slug' => 'test',
            ]),
        ]);

        $this->artisan('site:create', [
            'name' => 'test',
            '--clone' => 'git@github.com:user/repo.git',
            '--json' => true,
        ]);

        Http::assertSent(function ($request) {
            return $request['template'] === 'user/repo';
        });
    });

    it('maps organization option to org in payload', function () {
        Http::fake([
            'https://orbit.test/api/sites' => Http::response([
                'success' => true,
                'slug' => 'org-site',
            ]),
        ]);

        $this->artisan('site:create', [
            'name' => 'org-site',
            '--organization' => 'my-org',
            '--json' => true,
        ]);

        Http::assertSent(function ($request) {
            return $request['org'] === 'my-org';
        });
    });

    it('includes fork flag only when cloning', function () {
        Http::fake([
            'https://orbit.test/api/sites' => Http::response([
                'success' => true,
                'slug' => 'forked',
            ]),
        ]);

        $this->artisan('site:create', [
            'name' => 'forked',
            '--clone' => 'other/repo',
            '--fork' => true,
            '--json' => true,
        ]);

        Http::assertSent(function ($request) {
            return $request['fork'] === true;
        });
    });
});

describe('URL normalization', function () {
    it('normalizes https github URLs', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('normalizeRepoUrl');

        expect($method->invoke($command, 'https://github.com/user/repo'))->toBe('user/repo');
        expect($method->invoke($command, 'https://github.com/user/repo.git'))->toBe('user/repo');
    });

    it('normalizes ssh github URLs', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('normalizeRepoUrl');

        expect($method->invoke($command, 'git@github.com:user/repo.git'))->toBe('user/repo');
        expect($method->invoke($command, 'git@github.com:user/repo'))->toBe('user/repo');
    });

    it('passes through owner/repo format unchanged', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('normalizeRepoUrl');

        expect($method->invoke($command, 'user/repo'))->toBe('user/repo');
    });
});
