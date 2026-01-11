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

        $logger->info('Running database migrations...');

        $result = Process::path($context->projectPath)
            ->env($context->getPhpEnv())
            ->timeout(120)
            ->run('php artisan migrate --force');

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
