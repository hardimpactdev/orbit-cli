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
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register HTTP client factory
        $this->app->singleton(Factory::class, fn($app) => new Factory());

        // Alias for facade
        $this->app->alias(Factory::class, 'http');
    }
}
