<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class GenerateAppKey
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        if (! file_exists("{$context->projectPath}/artisan")) {
            $logger->info('Skipping key:generate - no artisan file found');

            return StepResult::success();
        }

        $envPath = "{$context->projectPath}/.env";
        if (! file_exists($envPath)) {
            return StepResult::failed('.env file not found');
        }

        $logger->info('Generating application key...');

        // Use env -i to clear inherited environment variables (especially APP_KEY from the phar)
        // This prevents Laravel from seeing the phar APP_KEY and refusing to write to .env
        $home = $context->getHomeDir();
        $command = "env -i HOME={$home} PATH={$home}/.config/herd-lite/bin:{$home}/.local/bin:/usr/local/bin:/usr/bin:/bin php artisan key:generate --force";

        $result = Process::path($context->projectPath)
            ->timeout(30)
            ->run($command);

        $logger->log('key:generate output: '.trim($result->output()));

        if (! $result->successful()) {
            return StepResult::failed('key:generate failed: '.$result->errorOutput());
        }

        // Verify APP_KEY was set
        clearstatcache(true, $envPath);
        $env = file_get_contents($envPath);

        if (preg_match('/^APP_KEY=(.+)$/m', $env, $matches)) {
            return StepResult::success(['app_key' => trim($matches[1])]);
        }

        return StepResult::failed('APP_KEY is empty after key:generate');
    }
}
