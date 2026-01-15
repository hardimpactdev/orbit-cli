<?php

use App\Services\ConfigManager;
use App\Services\McpClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->app->instance(ConfigManager::class, $this->configManager);
});

it('constructs base url from config', function () {
    $this->configManager->shouldReceive('get')
        ->with('sequence.url', 'http://localhost:8000')
        ->andReturn('http://example.com');

    $client = new McpClient($this->configManager);

    expect($client)->toBeInstanceOf(McpClient::class);
});

it('calls tool and returns result', function () {
    $this->configManager->shouldReceive('get')
        ->with('sequence.url', 'http://localhost:8000')
        ->andReturn('http://example.com');

    Http::fake([
        'example.com/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'result' => ['data' => 'test'],
            'id' => 'test-id',
        ]),
    ]);

    $client = new McpClient($this->configManager);
    $result = $client->callTool('test-tool', ['arg' => 'value']);

    expect($result)->toHaveKey('data')
        ->and($result['data'])->toBe('test');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'example.com/mcp')
            && $request['method'] === 'tools/call'
            && $request['params']['name'] === 'test-tool';
    });
});

it('throws exception on http failure', function () {
    $this->configManager->shouldReceive('get')
        ->with('sequence.url', 'http://localhost:8000')
        ->andReturn('http://example.com');

    Http::fake([
        'example.com/mcp' => Http::response([], 500),
    ]);

    $client = new McpClient($this->configManager);

    expect(fn () => $client->callTool('test-tool', []))
        ->toThrow(RuntimeException::class);
});

it('throws exception on mcp error response', function () {
    $this->configManager->shouldReceive('get')
        ->with('sequence.url', 'http://localhost:8000')
        ->andReturn('http://example.com');

    Http::fake([
        'example.com/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'error' => ['message' => 'Tool not found'],
            'id' => 'test-id',
        ]),
    ]);

    $client = new McpClient($this->configManager);

    expect(fn () => $client->callTool('nonexistent-tool', []))
        ->toThrow(RuntimeException::class, 'Tool not found');
});
