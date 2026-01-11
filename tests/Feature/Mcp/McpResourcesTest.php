<?php

declare(strict_types=1);

use App\Mcp\Resources\ConfigResource;
use App\Mcp\Resources\EnvTemplateResource;
use App\Mcp\Resources\InfrastructureResource;
use App\Mcp\Resources\SitesResource;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\SiteScanner;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Resources\HasUriTemplate;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->dockerManager = Mockery::mock(DockerManager::class);
    $this->siteScanner = Mockery::mock(SiteScanner::class);

    $this->app->instance(ConfigManager::class, $this->configManager);
    $this->app->instance(DockerManager::class, $this->dockerManager);
    $this->app->instance(SiteScanner::class, $this->siteScanner);
});

describe('InfrastructureResource', function () {
    it('has correct uri', function () {
        $resource = app(InfrastructureResource::class);
        expect($resource->uri())->toBe('launchpad://infrastructure');
    });

    it('has correct mime type', function () {
        $resource = app(InfrastructureResource::class);
        expect($resource->mimeType())->toBe('application/json');
    });

    it('returns service status information', function () {
        $this->dockerManager->shouldReceive('isRunning')->andReturn(true);
        $this->dockerManager->shouldReceive('getHealthStatus')->andReturn('healthy');

        $resource = app(InfrastructureResource::class);
        $request = new Request([]);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });

    it('tracks running and stopped services', function () {
        // Some running, some stopped
        $this->dockerManager->shouldReceive('isRunning')
            ->with('launchpad-dns')->andReturn(true);
        $this->dockerManager->shouldReceive('isRunning')
            ->with('launchpad-php-83')->andReturn(true);
        $this->dockerManager->shouldReceive('isRunning')
            ->with('launchpad-php-84')->andReturn(false);
        $this->dockerManager->shouldReceive('isRunning')
            ->withAnyArgs()->andReturn(true);
        $this->dockerManager->shouldReceive('getHealthStatus')->andReturn('healthy');

        $resource = app(InfrastructureResource::class);
        $request = new Request([]);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});

describe('ConfigResource', function () {
    it('has correct uri', function () {
        $resource = app(ConfigResource::class);
        expect($resource->uri())->toBe('launchpad://config');
    });

    it('has correct mime type', function () {
        $resource = app(ConfigResource::class);
        expect($resource->mimeType())->toBe('application/json');
    });

    it('returns configuration data', function () {
        $this->configManager->shouldReceive('getTld')->andReturn('test');
        $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.4');
        $this->configManager->shouldReceive('getPaths')->andReturn(['/home/user/projects']);
        $this->configManager->shouldReceive('getConfigPath')->andReturn('/home/user/.config/launchpad');
        $this->configManager->shouldReceive('getEnabledServices')->andReturn(['reverb' => true]);

        $resource = app(ConfigResource::class);
        $request = new Request([]);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});

describe('EnvTemplateResource', function () {
    it('has correct mime type', function () {
        $resource = new EnvTemplateResource;
        expect($resource->mimeType())->toBe('text/plain');
    });

    it('implements HasUriTemplate interface', function () {
        $resource = new EnvTemplateResource;
        expect($resource)->toBeInstanceOf(HasUriTemplate::class);
    });

    it('has uri template with type parameter', function () {
        $resource = new EnvTemplateResource;
        $template = $resource->uriTemplate();

        expect($template->template())->toBe('launchpad://env-template/{type}');
    });

    it('returns database template with postgres config', function () {
        $resource = new EnvTemplateResource;
        $request = new Request(['type' => 'database']);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });

    it('returns redis template', function () {
        $resource = new EnvTemplateResource;
        $request = new Request(['type' => 'redis']);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });

    it('returns mail template with mailpit config', function () {
        $resource = new EnvTemplateResource;
        $request = new Request(['type' => 'mail']);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });

    it('returns broadcasting template with reverb config', function () {
        $resource = new EnvTemplateResource;
        $request = new Request(['type' => 'broadcasting']);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });

    it('returns full template by default', function () {
        $resource = new EnvTemplateResource;
        $request = new Request([]);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});

describe('SitesResource', function () {
    it('has correct uri', function () {
        $resource = app(SitesResource::class);
        expect($resource->uri())->toBe('launchpad://sites');
    });

    it('has correct mime type', function () {
        $resource = app(SitesResource::class);
        expect($resource->mimeType())->toBe('application/json');
    });

    it('returns sites list with metadata', function () {
        $this->siteScanner->shouldReceive('scanSites')->andReturn([
            [
                'name' => 'mysite',
                'domain' => 'mysite.test',
                'path' => '/path/to/mysite',
                'php_version' => '8.4',
            ],
        ]);
        $this->configManager->shouldReceive('getTld')->andReturn('test');
        $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.4');

        $resource = app(SitesResource::class);
        $request = new Request([]);
        $response = $resource->handle($request);

        expect($response)->toBeInstanceOf(ResponseFactory::class);
    });
});
