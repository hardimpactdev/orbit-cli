<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\DockerManager;
use App\Services\PhpComposeGenerator;
use LaravelZero\Framework\Commands\Command;

class StartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'start {--json : Output as JSON}';

    protected $description = 'Start all Launchpad services';

    public function handle(
        DockerManager $dockerManager,
        CaddyfileGenerator $caddyfileGenerator,
        PhpComposeGenerator $phpComposeGenerator
    ): int {
        $results = [];

        // Generate configuration
        $configResult = $this->runStep('config', 'Generating configuration', function () use ($caddyfileGenerator, $phpComposeGenerator) {
            $phpComposeGenerator->generate();
            $caddyfileGenerator->generate();

            return true;
        });
        $results['config'] = $configResult;
        $allSuccess = $configResult;

        // Start services in order
        $services = ['dns', 'php', 'caddy', 'postgres', 'redis', 'mailpit', 'horizon'];

        foreach ($services as $service) {
            $result = $this->runStep($service, "Starting {$service}", fn () => $dockerManager->start($service));
            $results[$service] = $result;
            $allSuccess = $allSuccess && $result;
        }

        if ($this->wantsJson()) {
            return $this->outputJson([
                'success' => $allSuccess,
                'data' => [
                    'action' => 'start',
                    'services' => $results,
                ],
            ], $allSuccess ? self::SUCCESS : ExitCode::ServiceFailed->value);
        }

        $this->newLine();
        if ($allSuccess) {
            $this->info('Launchpad is running!');
        } else {
            $this->warn('Some services failed to start.');
        }

        return $allSuccess ? self::SUCCESS : ExitCode::ServiceFailed->value;
    }

    private function runStep(string $name, string $label, callable $callback): bool
    {
        if ($this->wantsJson()) {
            try {
                return (bool) $callback();
            } catch (\Exception) {
                return false;
            }
        }

        $result = true;
        $this->task($label, function () use ($callback, &$result) {
            try {
                $result = (bool) $callback();
            } catch (\Exception) {
                $result = false;
            }

            return $result;
        });

        return $result;
    }
}
