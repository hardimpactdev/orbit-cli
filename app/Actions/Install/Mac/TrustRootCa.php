<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\Process;

final readonly class TrustRootCa
{
    public function __construct(
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($context->skipTrust) {
            $logger->skip('Certificate trust skipped');

            return StepResult::success();
        }

        // Check if Caddy container is running
        if (! $this->dockerManager->isRunning('orbit-caddy')) {
            $logger->warn('Caddy container not running - certificate trust skipped');

            return StepResult::success();
        }

        $tempCert = '/tmp/orbit-caddy-root.crt';

        // Extract certificate from container
        $extractResult = Process::run(
            "docker exec orbit-caddy cat /data/caddy/pki/authorities/local/root.crt > {$tempCert}"
        );

        if (! $extractResult->successful() || ! file_exists($tempCert) || filesize($tempCert) === 0) {
            $logger->warn('Failed to extract certificate - certificates may not be trusted');
            $logger->info('Try visiting https://localhost first to trigger certificate generation');

            return StepResult::success();
        }

        // Add to macOS Keychain
        $logger->step('Adding to macOS Keychain (sudo required)...');

        $trustResult = Process::run(
            "sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain {$tempCert}"
        );

        // Cleanup
        @unlink($tempCert);

        if (! $trustResult->successful()) {
            $logger->warn('Failed to add certificate to Keychain');

            return StepResult::success();
        }

        $logger->success('Certificate trusted');
        $logger->info('You may need to restart your browser');

        return StepResult::success();
    }
}
