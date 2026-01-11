<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Console\Commands\InspectorCommand;
use Laravel\Mcp\Console\Commands\MakePromptCommand;
use Laravel\Mcp\Console\Commands\MakeResourceCommand;
use Laravel\Mcp\Console\Commands\MakeServerCommand;
use Laravel\Mcp\Console\Commands\MakeToolCommand;
use Laravel\Mcp\Console\Commands\StartCommand;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Registrar;

/**
 * MCP Service Provider for Laravel Zero.
 *
 * This is a simplified version of Laravel\Mcp\Server\McpServiceProvider
 * that works with Laravel Zero (no router dependency).
 */
class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registrar::class, fn (): Registrar => new Registrar);

        $this->mergeConfigFrom(
            base_path('vendor/laravel/mcp/config/mcp.php'),
            'mcp'
        );
    }

    public function boot(): void
    {
        $this->registerMcpScope();
        $this->registerContainerCallbacks();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }
    }

    protected function registerContainerCallbacks(): void
    {
        $this->app->resolving(Request::class, function (Request $request, $app): void {
            if ($app->bound('mcp.request')) {
                /** @var Request $currentRequest */
                $currentRequest = $app->make('mcp.request');

                $request->setArguments($currentRequest->all());
                $request->setSessionId($currentRequest->sessionId());
                $request->setMeta($currentRequest->meta());
            }
        });
    }

    protected function registerCommands(): void
    {
        $this->commands([
            StartCommand::class,
            MakeServerCommand::class,
            MakeToolCommand::class,
            MakePromptCommand::class,
            MakeResourceCommand::class,
            InspectorCommand::class,
        ]);
    }

    protected function registerMcpScope(): void
    {
        $this->app->booted(function (): void {
            Registrar::ensureMcpScope();
        });
    }
}
