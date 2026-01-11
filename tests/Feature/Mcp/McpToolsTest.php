<?php

declare(strict_types=1);

use App\Mcp\Tools\LogsTool;
use App\Mcp\Tools\PhpTool;
use App\Mcp\Tools\RestartTool;
use App\Mcp\Tools\SitesTool;
use App\Mcp\Tools\StartTool;
use App\Mcp\Tools\StatusTool;
use App\Mcp\Tools\StopTool;
use App\Mcp\Tools\WorktreesTool;
use App\Services\ConfigManager;
use App\Services\DatabaseService;
use App\Services\DockerManager;
use App\Services\SiteScanner;
use App\Services\WorktreeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->dockerManager = Mockery::mock(DockerManager::class);
    $this->siteScanner = Mockery::mock(SiteScanner::class);
    $this->databaseService = Mockery::mock(DatabaseService::class);
    $this->worktreeService = Mockery::mock(WorktreeService::class);

    $this->app->instance(ConfigManager::class, $this->configManager);
    $this->app->instance(DockerManager::class, $this->dockerManager);
    $this->app->instance(SiteScanner::class, $this->siteScanner);
    $this->app->instance(DatabaseService::class, $this->databaseService);
    $this->app->instance(WorktreeService::class, $this->worktreeService);
});

describe('StatusTool', function () {
    it('returns structured status response', function () {
        $this->dockerManager->shouldReceive('isRunning')->andReturn(true);
        $this->dockerManager->shouldReceive('getHealthStatus')->andReturn('healthy');
        $this->siteScanner->shouldReceive('scanSites')->andReturn([
            ['name' => 'mysite', 'domain' => 'mysite.test'],
        ]);
        $this->configManager->shouldReceive('getConfigPath')->andReturn('/home/user/.config/launchpad');
        $this->configManager->shouldReceive('getTld')->andReturn('test');
        $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.4');
        $this->configManager->shouldReceive('isServiceEnabled')->andReturn(false);

        $tool = app(StatusTool::class);
        $request = new Request([]);
        $response = $tool->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });

    it('has empty schema (no input parameters)', function () {
        $tool = app(StatusTool::class);
        $schema = Mockery::mock(JsonSchema::class);
        $result = $tool->schema($schema);

        expect($result)->toBe([]);
    });

    it('has correct description', function () {
        $tool = app(StatusTool::class);
        expect($tool->description())->toContain('status');
    });
});

describe('StartTool', function () {
    it('starts services and returns success', function () {
        $this->dockerManager->shouldReceive('startServices')->once()->andReturn(true);

        $tool = app(StartTool::class);
        $request = new Request([]);
        $response = $tool->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });

    it('has empty schema', function () {
        $tool = app(StartTool::class);
        $schema = Mockery::mock(JsonSchema::class);
        $result = $tool->schema($schema);

        expect($result)->toBe([]);
    });
});

describe('StopTool', function () {
    it('stops services and returns success', function () {
        $this->dockerManager->shouldReceive('stopServices')->once()->andReturn(true);

        $tool = app(StopTool::class);
        $request = new Request([]);
        $response = $tool->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});

describe('RestartTool', function () {
    it('restarts services and returns success', function () {
        $this->dockerManager->shouldReceive('stopServices')->once()->andReturn(true);
        $this->dockerManager->shouldReceive('startServices')->once()->andReturn(true);

        $tool = app(RestartTool::class);
        $request = new Request([]);
        $response = $tool->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});

describe('SitesTool', function () {
    it('returns list of sites', function () {
        $this->siteScanner->shouldReceive('scanSites')->andReturn([
            [
                'name' => 'mysite',
                'domain' => 'mysite.test',
                'path' => '/path/to/mysite',
                'php_version' => '8.4',
            ],
        ]);
        $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.4');

        $tool = app(SitesTool::class);
        $request = new Request([]);
        $response = $tool->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});

describe('PhpTool', function () {
    it('has required schema parameters', function () {
        $tool = app(PhpTool::class);

        $stringMock = Mockery::mock();
        $stringMock->shouldReceive('required')->andReturnSelf();
        $stringMock->shouldReceive('description')->andReturnSelf();
        $stringMock->shouldReceive('enum')->andReturnSelf();

        $schema = Mockery::mock(JsonSchema::class);
        $schema->shouldReceive('string')->andReturn($stringMock);

        $result = $tool->schema($schema);

        expect($result)->toHaveKey('site');
        expect($result)->toHaveKey('action');
        expect($result)->toHaveKey('version');
    });

    it('returns error for missing site parameter', function () {
        $tool = app(PhpTool::class);
        $request = new Request(['action' => 'get']);
        $response = $tool->handle($request);

        // Error responses are Response objects, not ResponseFactory
        expect($response)->toBeInstanceOf(Response::class);
    });

    it('gets php version for a site', function () {
        $this->databaseService->shouldReceive('getPhpVersion')->with('mysite')->andReturn('8.4');
        $this->databaseService->shouldReceive('getProjectOverride')->with('mysite')->andReturn([
            'path' => '/path/to/mysite',
        ]);
        $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.5');

        $tool = app(PhpTool::class);
        $request = new Request(['site' => 'mysite', 'action' => 'get']);
        $response = $tool->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});

describe('LogsTool', function () {
    it('has required service parameter', function () {
        $tool = app(LogsTool::class);

        $stringMock = Mockery::mock();
        $stringMock->shouldReceive('required')->andReturnSelf();
        $stringMock->shouldReceive('description')->andReturnSelf();

        $intMock = Mockery::mock();
        $intMock->shouldReceive('default')->andReturnSelf();
        $intMock->shouldReceive('description')->andReturnSelf();
        $intMock->shouldReceive('min')->andReturnSelf();
        $intMock->shouldReceive('max')->andReturnSelf();

        $schema = Mockery::mock(JsonSchema::class);
        $schema->shouldReceive('string')->andReturn($stringMock);
        $schema->shouldReceive('integer')->andReturn($intMock);

        $result = $tool->schema($schema);

        expect($result)->toHaveKey('service');
        expect($result)->toHaveKey('lines');
    });
});

describe('WorktreesTool', function () {
    it('returns worktrees for all sites when no site specified', function () {
        $this->worktreeService->shouldReceive('getAllWorktrees')->andReturn([]);
        $this->configManager->shouldReceive('getTld')->andReturn('test');

        $tool = app(WorktreesTool::class);
        $request = new Request([]);
        $response = $tool->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });

    it('returns worktrees for specific site when specified', function () {
        $this->worktreeService->shouldReceive('getSiteWorktrees')->with('mysite')->andReturn([]);
        $this->configManager->shouldReceive('getTld')->andReturn('test');

        $tool = app(WorktreesTool::class);
        $request = new Request(['site' => 'mysite']);
        $response = $tool->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});
