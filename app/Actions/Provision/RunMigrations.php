<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class RunMigrations
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $artisanPath = "{$context->projectPath}/artisan";

        if (! file_exists($artisanPath)) {
            $logger->info('Skipping migrations - no artisan file found');

            return StepResult::success();
        }

        // Determine PHP version for container (remove dot: 8.5 -> 85)
        $phpVersion = $context->phpVersion ?? '8.5';
        $containerVersion = str_replace('.', '', $phpVersion);
        $containerName = "launchpad-php-{$containerVersion}";

        // Clear config cache to ensure fresh .env values are loaded
        $logger->info('Clearing config cache...');
        $clearResult = Process::timeout(30)->run(
            "docker exec {$containerName} php {$context->projectPath}/artisan config:clear"
        );

        if (! $clearResult->successful()) {
            $logger->warn('config:clear failed: '.$clearResult->errorOutput());
        }

        $logger->info("Running database migrations via {$containerName}...");

        // Run migrations through the PHP container so it can access launchpad-postgres
        $result = Process::timeout(120)->run(
            "docker exec {$containerName} php {$context->projectPath}/artisan migrate --force"
        );

        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());
        $exitCode = $result->exitCode();

        $logger->log("migrate exit code: {$exitCode}");
        if ($output) {
            $logger->log("migrate stdout: {$output}");
        }
        if ($errorOutput) {
            $logger->log("migrate stderr: {$errorOutput}");
        }

        if (! $result->successful()) {
            $error = $errorOutput ?: $output ?: 'Unknown error';

            return StepResult::failed("migrate failed (exit {$exitCode}): {$error}");
        }

        $logger->info('Migrations completed successfully');

        return StepResult::success();
    }
}
