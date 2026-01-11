<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\DockerManager;
use LaravelZero\Framework\Commands\Command;

class HorizonStartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'horizon:start {--json : Output as JSON}';

    protected $description = 'Start Horizon queue worker';

    public function handle(DockerManager $dockerManager): int
    {
        // Check if already running
        if ($dockerManager->isRunning('launchpad-horizon')) {
            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'started' => false,
                    'already_running' => true,
                ]);
            }
            $this->info('Horizon is already running.');

            return self::SUCCESS;
        }

        // Start via Docker
        $result = $dockerManager->start('horizon');

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'started' => $result,
                'already_running' => false,
            ]);
        }

        if ($result) {
            $this->info('Horizon started successfully.');

            return self::SUCCESS;
        }

        $this->error('Horizon failed to start: '.$dockerManager->getLastError());

        return self::FAILURE;
    }
}
