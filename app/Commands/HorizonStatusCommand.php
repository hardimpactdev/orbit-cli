<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\DockerManager;
use LaravelZero\Framework\Commands\Command;

class HorizonStatusCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:status {--json : Output as JSON}';

    protected $description = 'Check Horizon status';

    public function handle(DockerManager $dockerManager): int
    {
        $isRunning = $dockerManager->isRunning('launchpad-horizon');
        $health = $dockerManager->getHealthStatus('launchpad-horizon');

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'running' => $isRunning,
                'health' => $health,
            ]);
        }

        if ($isRunning) {
            $healthStatus = $health ? " ({$health})" : '';
            $this->info("Horizon is running{$healthStatus}");
        } else {
            $this->warn('Horizon is not running');
        }

        return self::SUCCESS;
    }
}
