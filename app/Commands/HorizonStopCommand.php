<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\DockerManager;
use LaravelZero\Framework\Commands\Command;

class HorizonStopCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:stop {--json : Output as JSON}';

    protected $description = 'Stop Horizon queue worker';

    public function handle(DockerManager $dockerManager): int
    {
        // Check if not running
        if (! $dockerManager->isRunning('launchpad-horizon')) {
            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'stopped' => true,
                    'was_running' => false,
                ]);
            }
            $this->info('Horizon is not running.');

            return self::SUCCESS;
        }

        // Stop via Docker
        $result = $dockerManager->stop('horizon');

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'stopped' => $result,
                'was_running' => true,
            ]);
        }

        if ($result) {
            $this->info('Horizon stopped successfully.');

            return self::SUCCESS;
        }

        $this->error('Horizon failed to stop: '.$dockerManager->getLastError());

        return self::FAILURE;
    }
}
