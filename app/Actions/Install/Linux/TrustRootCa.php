<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

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

        // On Linux, add to system trust store
        $trustResult = $this->addToLinuxTrustStore($tempCert, $logger);

        // Cleanup
        @unlink($tempCert);

        if ($trustResult) {
            $logger->success('Certificate trusted');
        }

        return StepResult::success();
    }

    private function addToLinuxTrustStore(string $certPath, InstallLogger $logger): bool
    {
        // Try update-ca-certificates (Debian/Ubuntu)
        if (file_exists('/usr/local/share/ca-certificates')) {
            $result = Process::run(
                "sudo cp {$certPath} /usr/local/share/ca-certificates/orbit-caddy-root.crt && sudo update-ca-certificates"
            );

            if ($result->successful()) {
                return true;
            }
        }

        // Try trust anchor (Fedora/RHEL)
        if (file_exists('/etc/pki/ca-trust/source/anchors')) {
            $result = Process::run(
                "sudo cp {$certPath} /etc/pki/ca-trust/source/anchors/orbit-caddy-root.crt && sudo update-ca-trust"
            );

            if ($result->successful()) {
                return true;
            }
        }

        $logger->warn('Could not automatically trust certificate - you may need to do this manually');
        $logger->info('Certificate extracted to: '.$certPath);

        return false;
    }
}
