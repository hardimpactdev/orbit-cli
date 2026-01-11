<?php

declare(strict_types=1);

use App\Mcp\Prompts\ConfigureLaravelEnvPrompt;
use App\Mcp\Prompts\SetupHorizonPrompt;
use Laravel\Mcp\Response;

describe('ConfigureLaravelEnvPrompt', function () {
    it('has correct description', function () {
        $prompt = new ConfigureLaravelEnvPrompt;
        expect($prompt->description())->toContain('Laravel');
        expect($prompt->description())->toContain('.env');
    });

    it('requires project_slug argument', function () {
        $prompt = new ConfigureLaravelEnvPrompt;
        $arguments = $prompt->arguments();

        expect($arguments)->toHaveCount(1);
        expect($arguments[0]->name)->toBe('project_slug');
        expect($arguments[0]->required)->toBeTrue();
    });

    it('returns conversation with project slug', function () {
        $prompt = new ConfigureLaravelEnvPrompt;
        $messages = $prompt->handle(['project_slug' => 'my-app']);

        expect($messages)->toBeArray();
        expect($messages)->not->toBeEmpty();

        // Check that messages are Response objects and contain the project slug
        foreach ($messages as $message) {
            expect($message)->toBeInstanceOf(Response::class);
        }

        // Convert content to string to check for project slug
        $content = (string) $messages[0]->content();
        expect($content)->toContain('my-app');
    });

    it('includes database configuration guidance', function () {
        $prompt = new ConfigureLaravelEnvPrompt;
        $messages = $prompt->handle(['project_slug' => 'test-project']);

        // Collect all text content
        $content = '';
        foreach ($messages as $message) {
            $content .= (string) $message->content();
        }

        expect($content)->toContain('DB_CONNECTION=pgsql');
        expect($content)->toContain('launchpad-postgres');
    });

    it('includes redis configuration guidance', function () {
        $prompt = new ConfigureLaravelEnvPrompt;
        $messages = $prompt->handle(['project_slug' => 'test-project']);

        $content = '';
        foreach ($messages as $message) {
            $content .= (string) $message->content();
        }

        expect($content)->toContain('REDIS_HOST=launchpad-redis');
    });

    it('includes mail configuration guidance', function () {
        $prompt = new ConfigureLaravelEnvPrompt;
        $messages = $prompt->handle(['project_slug' => 'test-project']);

        $content = '';
        foreach ($messages as $message) {
            $content .= (string) $message->content();
        }

        expect($content)->toContain('MAIL_HOST=launchpad-mailpit');
    });
});

describe('SetupHorizonPrompt', function () {
    it('has correct description', function () {
        $prompt = new SetupHorizonPrompt;
        expect($prompt->description())->toContain('Horizon');
    });

    it('requires project_slug argument', function () {
        $prompt = new SetupHorizonPrompt;
        $arguments = $prompt->arguments();

        expect($arguments)->toHaveCount(1);
        expect($arguments[0]->name)->toBe('project_slug');
        expect($arguments[0]->required)->toBeTrue();
    });

    it('returns conversation with horizon setup instructions', function () {
        $prompt = new SetupHorizonPrompt;
        $messages = $prompt->handle(['project_slug' => 'my-app']);

        expect($messages)->toBeArray();
        expect($messages)->not->toBeEmpty();

        $content = '';
        foreach ($messages as $message) {
            expect($message)->toBeInstanceOf(Response::class);
            $content .= (string) $message->content();
        }

        expect($content)->toContain('Horizon');
        expect($content)->toContain('Redis');
    });

    it('mentions queue configuration', function () {
        $prompt = new SetupHorizonPrompt;
        $messages = $prompt->handle(['project_slug' => 'test-app']);

        $content = '';
        foreach ($messages as $message) {
            $content .= (string) $message->content();
        }

        expect($content)->toContain('QUEUE_CONNECTION=redis');
    });
});
