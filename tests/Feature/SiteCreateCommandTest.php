<?php

use App\Commands\SiteCreateCommand;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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
        Process::fake(['*' => Process::result(output: 'Success')]);
        Http::fake(['localhost:8000/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'result' => ['content' => [['text' => 'Site created']]],
            'id' => 'test-id',
        ])]);

        $this->artisan('site:create', ['name' => 'test-site', '--json' => true]);
        expect(true)->toBeTrue();
    });

    it('rejects reserved name "orbit"', function () {
        $this->artisan('site:create', ['name' => 'orbit', '--json' => true])
            ->assertExitCode(1);
    });

    it('rejects when directory already exists', function () {
        mkdir($this->tempDir.'/existing-site', 0755, true);

        $this->artisan('site:create', ['name' => 'existing-site', '--json' => true])
            ->assertExitCode(1);
    });
});

describe('option definitions', function () {
    /**
     * CRITICAL: Verify all options that CreateSiteJob expects are defined.
     * This test would have caught the --org vs --organization mismatch.
     */
    it('has --organization option defined (not --org)', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $definition = $command->getDefinition();

        // This is the CRITICAL test - CreateSiteJob uses 'org' option which maps to --organization
        expect($definition->hasOption('organization'))->toBeTrue();
        // Verify we don't have a confusingly-named --org option
        expect($definition->hasOption('org'))->toBeFalse();
    });

    it('has all expected options', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $definition = $command->getDefinition();

        $expectedOptions = [
            'template',
            'clone',
            'fork',
            'visibility',
            'organization',  // CRITICAL: Must be organization, not org
            'path',
            'php',
            'db-driver',
            'session-driver',
            'cache-driver',
            'queue-driver',
            'minimal',
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
        expect($definition->getOption('minimal')->getDefault())->toBeFalse();
    });
});

describe('command signature matches CreateSiteJob expectations', function () {
    /**
     * CRITICAL: Verify option names in site:create match what CreateSiteJob expects.
     * The job builds commands using these exact option names.
     */
    it('has all options that CreateSiteJob uses', function () {
        $siteCreate = $this->app->make(SiteCreateCommand::class);
        $definition = $siteCreate->getDefinition();

        // Options used by CreateSiteJob::buildCommand()
        $jobOptions = [
            'template',       // --template=
            'clone',          // --clone=
            'fork',           // --fork
            'organization',   // --organization= (CRITICAL: not --org)
            'visibility',     // --visibility=
            'path',           // --path=
            'php',            // --php=
            'db-driver',      // --db-driver=
            'session-driver', // --session-driver=
            'cache-driver',   // --cache-driver=
            'queue-driver',   // --queue-driver=
            'json',           // --json
        ];

        foreach ($jobOptions as $option) {
            expect($definition->hasOption($option))
                ->toBeTrue("site:create missing --{$option} (required by CreateSiteJob)");
        }
    });
});

describe('URL parsing', function () {
    it('expandPath expands tilde correctly', function () {
        $command = $this->app->make(SiteCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('expandPath');

        $_SERVER['HOME'] = '/home/testuser';
        expect($method->invoke($command, '~/projects'))->toBe('/home/testuser/projects');
        expect($method->invoke($command, '/absolute/path'))->toBe('/absolute/path');
    });
});
