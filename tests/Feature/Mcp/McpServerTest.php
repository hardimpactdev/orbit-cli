<?php

declare(strict_types=1);

use App\Mcp\Servers\LaunchpadServer;

it('has correct server metadata', function () {
    // Create server instance directly to test properties
    $reflection = new ReflectionClass(LaunchpadServer::class);

    $name = $reflection->getProperty('name');
    $name->setAccessible(true);

    $version = $reflection->getProperty('version');
    $version->setAccessible(true);

    $instructions = $reflection->getProperty('instructions');
    $instructions->setAccessible(true);

    // Create instance without constructor dependencies
    $server = $reflection->newInstanceWithoutConstructor();

    expect($name->getValue($server))->toBe('Launchpad');
    expect($version->getValue($server))->toBe('1.0.0');
    expect($instructions->getValue($server))->toContain('Launchpad');
    expect($instructions->getValue($server))->toContain('PostgreSQL');
    expect($instructions->getValue($server))->toContain('Redis');
    expect($instructions->getValue($server))->toContain('launchpad-postgres');
});

it('registers all expected tools', function () {
    $reflection = new ReflectionClass(LaunchpadServer::class);
    $tools = $reflection->getProperty('tools');
    $tools->setAccessible(true);

    $server = $reflection->newInstanceWithoutConstructor();
    $toolClasses = $tools->getValue($server);

    expect($toolClasses)->toHaveCount(10);

    $toolNames = array_map(fn ($tool) => class_basename($tool), $toolClasses);

    expect($toolNames)->toContain('StatusTool');
    expect($toolNames)->toContain('StartTool');
    expect($toolNames)->toContain('StopTool');
    expect($toolNames)->toContain('RestartTool');
    expect($toolNames)->toContain('SitesTool');
    expect($toolNames)->toContain('PhpTool');
    expect($toolNames)->toContain('ProjectCreateTool');
    expect($toolNames)->toContain('ProjectDeleteTool');
    expect($toolNames)->toContain('LogsTool');
    expect($toolNames)->toContain('WorktreesTool');
});

it('registers all expected resources', function () {
    $reflection = new ReflectionClass(LaunchpadServer::class);
    $resources = $reflection->getProperty('resources');
    $resources->setAccessible(true);

    $server = $reflection->newInstanceWithoutConstructor();
    $resourceClasses = $resources->getValue($server);

    expect($resourceClasses)->toHaveCount(4);

    $resourceNames = array_map(fn ($resource) => class_basename($resource), $resourceClasses);

    expect($resourceNames)->toContain('InfrastructureResource');
    expect($resourceNames)->toContain('ConfigResource');
    expect($resourceNames)->toContain('EnvTemplateResource');
    expect($resourceNames)->toContain('SitesResource');
});

it('registers all expected prompts', function () {
    $reflection = new ReflectionClass(LaunchpadServer::class);
    $prompts = $reflection->getProperty('prompts');
    $prompts->setAccessible(true);

    $server = $reflection->newInstanceWithoutConstructor();
    $promptClasses = $prompts->getValue($server);

    expect($promptClasses)->toHaveCount(2);

    $promptNames = array_map(fn ($prompt) => class_basename($prompt), $promptClasses);

    expect($promptNames)->toContain('ConfigureLaravelEnvPrompt');
    expect($promptNames)->toContain('SetupHorizonPrompt');
});

it('includes important infrastructure warnings in instructions', function () {
    $reflection = new ReflectionClass(LaunchpadServer::class);
    $instructions = $reflection->getProperty('instructions');
    $instructions->setAccessible(true);

    $server = $reflection->newInstanceWithoutConstructor();
    $instructionText = $instructions->getValue($server);

    // Should warn not to install services locally
    expect($instructionText)->toContain('DO NOT');
    expect($instructionText)->toContain('launchpad-redis');
    expect($instructionText)->toContain('launchpad-mailpit');
});
