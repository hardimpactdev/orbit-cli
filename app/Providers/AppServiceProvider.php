<?php

namespace App\Providers;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load MCP routes for AI tool integration
        if (file_exists($aiRoutes = base_path('routes/ai.php'))) {
            require $aiRoutes;
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register HTTP client factory
        $this->app->singleton(Factory::class, fn ($app) => new Factory);

        // Alias for facade
        $this->app->alias(Factory::class, 'http');
    }
}
