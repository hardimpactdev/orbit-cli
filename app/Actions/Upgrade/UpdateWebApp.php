<?php

declare(strict_types=1);

namespace App\Actions\Upgrade;

use App\Services\ConfigManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final readonly class UpdateWebApp
{
    public function __construct(
        private ConfigManager $configManager,
    ) {}

    public function handle(): bool
    {
        $sourcePath = base_path('web');
        $destPath = $this->configManager->getWebAppPath();

        // Check if source exists (in development or phar)
        if (! File::isDirectory($sourcePath)) {
            // Source not found - nothing to update
            return true;
        }

        // Check if web app is installed
        if (! File::exists("{$destPath}/artisan")) {
            // Web app not installed - skip update
            return true;
        }

        // Backup database
        $dbPath = "{$destPath}/database.sqlite";
        if (File::exists($dbPath)) {
            File::copy($dbPath, "{$dbPath}.backup");
        }

        // Save existing .env
        $envPath = "{$destPath}/.env";
        $envContent = '';
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
        }

        // Copy new files (preserving certain directories and files)
        $this->updateWebAppFiles($sourcePath, $destPath);

        // Restore .env
        if ($envContent) {
            File::put($envPath, $envContent);
        }

        // Clear caches
        Process::timeout(60)
            ->path($destPath)
            ->run('php artisan cache:clear');

        Process::timeout(60)
            ->path($destPath)
            ->run('php artisan config:clear');

        Process::timeout(60)
            ->path($destPath)
            ->run('php artisan view:clear');

        // Run composer install
        $result = Process::timeout(300)
            ->path($destPath)
            ->run('composer install --no-dev --no-interaction --optimize-autoloader');

        if (! $result->successful()) {
            // Restore database backup on failure
            if (File::exists("{$dbPath}.backup")) {
                File::move("{$dbPath}.backup", $dbPath);
            }

            return false;
        }

        // Run migrations
        $migrateResult = Process::timeout(60)
            ->path($destPath)
            ->run('php artisan migrate --force');

        if (! $migrateResult->successful()) {
            // Restore database backup on failure
            if (File::exists("{$dbPath}.backup")) {
                File::move("{$dbPath}.backup", $dbPath);
            }

            return false;
        }

        // Clean up backup on success
        if (File::exists("{$dbPath}.backup")) {
            File::delete("{$dbPath}.backup");
        }

        return true;
    }

    private function updateWebAppFiles(string $source, string $destination): void
    {
        // Preserve these directories/files during update
        $preserveDirs = ['storage', '.env', 'database.sqlite'];
        $excludeDirs = ['vendor', 'node_modules', '.git'];

        // First, remove old files (except preserved ones)
        $this->cleanOldFiles($destination, $preserveDirs);

        // Then copy new files
        $this->recursiveCopy($source, $destination, $excludeDirs, ['.env']);

        // Ensure storage directories exist with proper permissions
        $storageDirs = [
            "{$destination}/storage/app",
            "{$destination}/storage/framework/cache",
            "{$destination}/storage/framework/sessions",
            "{$destination}/storage/framework/views",
            "{$destination}/storage/logs",
            "{$destination}/bootstrap/cache",
        ];

        foreach ($storageDirs as $dir) {
            File::ensureDirectoryExists($dir);
            chmod($dir, 0775);
        }
    }

    /**
     * @param  array<int, string>  $preserve
     */
    private function cleanOldFiles(string $path, array $preserve): void
    {
        $items = File::files($path);
        $directories = File::directories($path);

        // Remove files
        foreach ($items as $file) {
            $filename = $file->getFilename();
            if (! in_array($filename, $preserve)) {
                File::delete($file->getPathname());
            }
        }

        // Remove directories
        foreach ($directories as $dir) {
            $dirname = basename((string) $dir);
            if (! in_array($dirname, $preserve)) {
                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * @param  array<int, string>  $excludeDirs
     * @param  array<int, string>  $excludeFiles
     */
    private function recursiveCopy(string $source, string $destination, array $excludeDirs, array $excludeFiles, string $relativePath = ''): void
    {
        $items = File::files($source);
        $directories = File::directories($source);

        foreach ($items as $file) {
            $filename = $file->getFilename();
            if (in_array($filename, $excludeFiles)) {
                continue;
            }
            File::copy($file->getPathname(), "{$destination}/{$filename}");
        }

        foreach ($directories as $dir) {
            $dirname = basename((string) $dir);
            $newRelativePath = $relativePath ? "{$relativePath}/{$dirname}" : $dirname;

            $skip = false;
            foreach ($excludeDirs as $excludeDir) {
                if ($dirname === $excludeDir || str_starts_with($newRelativePath, (string) $excludeDir)) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $newDest = "{$destination}/{$dirname}";
            File::ensureDirectoryExists($newDest);
            $this->recursiveCopy($dir, $newDest, $excludeDirs, $excludeFiles, $newRelativePath);
        }
    }
}
