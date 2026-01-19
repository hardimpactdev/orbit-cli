<?php

namespace App\Providers;

use HardImpact\Orbit\OrbitServiceProvider;
use Illuminate\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register orbit-core's service provider to load migrations
        $this->app->register(OrbitServiceProvider::class);
    }

    public function boot(): void
    {
        $this->ensureDatabaseExists();
    }

    protected function ensureDatabaseExists(): void
    {
        $dbPath = config('database.connections.sqlite.database');

        if (! $dbPath || $dbPath === ':memory:') {
            return;
        }

        $configDir = dirname((string) $dbPath);

        if (! is_dir($configDir)) {
            @mkdir($configDir, 0755, true);
        }

        if (! file_exists($dbPath)) {
            @touch($dbPath);
        }
    }
}
