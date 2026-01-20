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

        // Install OrbStack
        $logger->step('Installing OrbStack...');
        $result = Process::timeout(300)->run('brew install --cask orbstack');

        if (! $result->successful()) {
            return StepResult::failed('Failed to install OrbStack: '.$result->errorOutput());
        }

        // Open OrbStack to complete setup
        Process::run('open -a OrbStack');
        $logger->step('Waiting for OrbStack initialization...');

        // Poll for readiness
        for ($i = 0; $i < 12; $i++) {
            sleep(5);
            if ($this->platformService->hasDocker()) { // @phpstan-ignore if.alwaysFalse
                $logger->success('OrbStack ready');

                return StepResult::success();
            }
        }

        return StepResult::failed('OrbStack not ready after 60 seconds');
    }
}
