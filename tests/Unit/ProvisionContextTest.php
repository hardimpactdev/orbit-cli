<?php

use App\Data\Provision\ProvisionContext;

it('creates context with required fields', function () {
    $context = new ProvisionContext(
        slug: 'my-project',
        projectPath: '/home/user/projects/my-project',
    );

    expect($context->slug)->toBe('my-project');
    expect($context->projectPath)->toBe('/home/user/projects/my-project');
});

it('has default values for optional fields', function () {
    $context = new ProvisionContext(
        slug: 'my-project',
        projectPath: '/tmp/my-project',
    );

    expect($context->visibility)->toBe('private');
    expect($context->minimal)->toBeFalse();
    expect($context->fork)->toBeFalse();
    expect($context->tld)->toBe('ccc');
    expect($context->githubRepo)->toBeNull();
    expect($context->cloneUrl)->toBeNull();
    expect($context->template)->toBeNull();
    expect($context->phpVersion)->toBeNull();
    expect($context->dbDriver)->toBeNull();
});

it('returns correct PHP environment', function () {
    $context = new ProvisionContext(
        slug: 'my-project',
        projectPath: '/tmp/my-project',
    );

    $env = $context->getPhpEnv();

    expect($env)->toHaveKey('HOME');
    expect($env)->toHaveKey('PATH');
    expect($env['PATH'])->toContain('.config/herd-lite/bin');
    expect($env['PATH'])->toContain('.local/bin');
});

it('returns home directory', function () {
    $context = new ProvisionContext(
        slug: 'my-project',
        projectPath: '/tmp/my-project',
    );

    $home = $context->getHomeDir();

    expect($home)->not->toBeEmpty();
});

it('accepts all optional fields', function () {
    $context = new ProvisionContext(
        slug: 'my-project',
        projectPath: '/tmp/my-project',
        githubRepo: 'user/my-project',
        cloneUrl: 'git@github.com:user/my-project.git',
        template: 'user/template',
        visibility: 'public',
        phpVersion: '8.4',
        dbDriver: 'pgsql',
        sessionDriver: 'redis',
        cacheDriver: 'redis',
        queueDriver: 'redis',
        minimal: true,
        fork: true,
        displayName: 'My Project',
        tld: 'test',
    );

    expect($context->githubRepo)->toBe('user/my-project');
    expect($context->cloneUrl)->toBe('git@github.com:user/my-project.git');
    expect($context->template)->toBe('user/template');
    expect($context->visibility)->toBe('public');
    expect($context->phpVersion)->toBe('8.4');
    expect($context->dbDriver)->toBe('pgsql');
    expect($context->sessionDriver)->toBe('redis');
    expect($context->cacheDriver)->toBe('redis');
    expect($context->queueDriver)->toBe('redis');
    expect($context->minimal)->toBeTrue();
    expect($context->fork)->toBeTrue();
    expect($context->displayName)->toBe('My Project');
    expect($context->tld)->toBe('test');
});
