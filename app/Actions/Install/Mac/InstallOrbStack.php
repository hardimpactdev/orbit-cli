<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use Illuminate\Support\Facades\Process;

final readonly class InstallOrbStack
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($context->skipDocker) {
            $logger->skip('Docker installation skipped');

            return StepResult::success();
        }

        // Check if Docker is already available
        if ($this->platformService->hasDocker()) {
            $runtime = $this->platformService->hasOrbStack() ? 'OrbStack' : 'Docker';
            $logger->skip("{$runtime} already running");

            return StepResult::success();
        }

        // Check if OrbStack is installed but not running
        $isInstalled = is_dir('/Applications/OrbStack.app');

        if (! $isInstalled) {
            // Install OrbStack via Homebrew
            $logger->step('Installing OrbStack via Homebrew...');
            $result = Process::timeout(600)->run('brew install --cask orbstack');

            if (! $result->successful()) {
                return StepResult::failed('Failed to install OrbStack: '.$result->errorOutput());
            }
        }

        // Open OrbStack to complete setup
        $logger->step('Starting OrbStack...');
        Process::run('open -a OrbStack');

        $logger->info('Waiting for OrbStack to initialize (this may take a moment on first run)...');

        // Poll for readiness with longer timeout for first-time setup
        $maxAttempts = 24; // 2 minutes total
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(5);
            if ($this->platformService->hasDocker()) { // @phpstan-ignore if.alwaysFalse
                $logger->success('OrbStack ready');

                return StepResult::success();
            }

            // Show progress every 30 seconds
            if ($i > 0 && $i % 6 === 0) {
                $elapsed = ($i + 1) * 5;
                $logger->info("Still waiting... ({$elapsed}s)");
            }
        }

        // If we get here, OrbStack didn't initialize in time
        $logger->warn('OrbStack is installed but may need manual setup.');
        $logger->info('Please open OrbStack from your Applications folder and complete the setup.');
        $logger->info('Then run: orbit install');

        return StepResult::failed('OrbStack not ready after 2 minutes. Please complete OrbStack setup manually and re-run orbit install.');
    }
}
