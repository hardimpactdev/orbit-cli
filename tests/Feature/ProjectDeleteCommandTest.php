<?php

use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class)->makePartial();
    $this->configManager->shouldReceive('getPaths')->andReturn(['/tmp/projects']);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getConfigPath')->andReturn('/tmp/.config/launchpad');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.4');
    $this->configManager->shouldReceive('get')->with('sequence.url', 'http://localhost:8000')->andReturn('http://localhost:8000');
    $this->configManager->shouldReceive('get')->with('reverb.url', '')->andReturn('');
    $this->configManager->shouldReceive('get')->with('paths', [])->andReturn(['/tmp/projects']);
    $this->app->instance(ConfigManager::class, $this->configManager);

    $this->caddyfileGenerator = Mockery::mock(CaddyfileGenerator::class);
    $this->caddyfileGenerator->shouldReceive('generate')->andReturn(true);
    $this->caddyfileGenerator->shouldReceive('reload')->andReturn(true);
    $this->app->instance(CaddyfileGenerator::class, $this->caddyfileGenerator);

    // Set HOME to temp for DeletionLogger
    $_SERVER['HOME'] = '/tmp';
    @mkdir('/tmp/.config/launchpad/logs/deletion', 0755, true);
});

afterEach(function () {
    // Clean up log files
    @unlink('/tmp/.config/launchpad/logs/deletion/test-project.log');
    @unlink('/tmp/.config/launchpad/logs/deletion/nonexistent.log');
});

it('deletes project via MCP when given slug with --force', function () {
    Http::fake([
        'localhost:8000/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'result' => [
                'content' => [['text' => '# Project Deleted']],
                'meta' => [
                    'id' => 1,
                    'name' => 'Test Project',
                    'slug' => 'test-project',
                ],
            ],
            'id' => 'test-id',
        ]),
    ]);

    // Just check that the command executes and makes MCP call
    $this->artisan('project:delete', ['slug' => 'test-project', '--force' => true, '--json' => true]);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://localhost:8000/mcp'
            && $request['params']['name'] === 'delete-project';
    });
});

it('handles MCP error response with warning and continues', function () {
    Http::fake([
        'localhost:8000/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'error' => ['message' => 'Project not found'],
            'id' => 'test-id',
        ]),
    ]);

    // Command should succeed but with warnings - MCP errors are non-fatal
    $this->artisan('project:delete', ['slug' => 'nonexistent', '--force' => true, '--json' => true])
        ->assertExitCode(0);
});

it('handles connection error with warning and continues', function () {
    Http::fake([
        'localhost:8000/mcp' => Http::response(status: 500),
    ]);

    // Command should succeed but with warnings - connection errors are non-fatal
    $this->artisan('project:delete', ['slug' => 'test-project', '--force' => true, '--json' => true])
        ->assertExitCode(0);
});
