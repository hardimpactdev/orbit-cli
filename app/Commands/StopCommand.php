<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\DockerManager;
use LaravelZero\Framework\Commands\Command;

class StopCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'stop {--json : Output as JSON}';

    protected $description = 'Stop all Launchpad services';

    public function handle(DockerManager $dockerManager): int
    {
        $results = [];
        $allSuccess = true;

        // Stop services in reverse order
        $services = ['horizon', 'caddy', 'mailpit', 'redis', 'postgres', 'php', 'dns'];

        foreach ($services as $service) {
            $result = $this->runStep($service, "Stopping {$service}", fn () => $dockerManager->stop($service));
            $results[$service] = $result;
            $allSuccess = $allSuccess && $result;
        }

        if ($this->wantsJson()) {
            return $this->outputJson([
                'success' => $allSuccess,
                'data' => [
                    'action' => 'stop',
                    'services' => $results,
                ],
            ], $allSuccess ? self::SUCCESS : ExitCode::ServiceFailed->value);
        }

        $this->newLine();
        if ($allSuccess) {
            $this->info('Launchpad stopped.');
        } else {
            $this->warn('Some services failed to stop.');
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
