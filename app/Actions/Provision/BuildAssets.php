<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class BuildAssets
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger, string $packageManager): StepResult
    {
        $packageJsonPath = "{$context->projectPath}/package.json";

        if (! file_exists($packageJsonPath)) {
            $logger->info('No package.json found, skipping asset build');

            return StepResult::success();
        }

        $packageJson = json_decode(file_get_contents($packageJsonPath), true);

        if (! isset($packageJson['scripts']['build'])) {
            $logger->info('No build script in package.json, skipping asset build');

            return StepResult::success();
        }

        $logger->info("Building assets with {$packageManager}...");

        $home = $context->getHomeDir();
        $projectPath = $context->projectPath;

        $result = match ($packageManager) {
            'bun' => $this->buildWithBun($projectPath, $home),
            'pnpm' => Process::path($projectPath)->timeout(600)->run('pnpm run build 2>&1'),
            'yarn' => Process::path($projectPath)->timeout(600)->run('yarn run build 2>&1'),
            default => Process::path($projectPath)->timeout(600)->run('npm run build 2>&1'),
        };

        $output = trim($result->output());
        $exitCode = $result->exitCode();

        $logger->log("Build exit code: {$exitCode}");
        if ($output) {
            $logger->log('Build output: '.substr($output, -1000));
        }

        if (! $result->successful()) {
            return StepResult::failed('Asset build failed: '.substr($output, 0, 500));
        }

        $logger->info('Assets built successfully');

        return StepResult::success();
    }

    private function buildWithBun(string $projectPath, string $home): \Illuminate\Process\ProcessResult
    {
        $bunPath = file_exists("{$home}/.bun/bin/bun") ? "{$home}/.bun/bin/bun" : 'bun';

        return Process::env(['PATH' => "{$home}/.bun/bin:".getenv('PATH')])
            ->path($projectPath)
            ->timeout(60)
            ->run("{$bunPath} run build 2>&1");
    }
}
