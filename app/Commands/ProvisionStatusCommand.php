<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

final class ProvisionStatusCommand extends Command
{
    protected $signature = 'provision:status
        {slug : Project slug}
        {--json : Output as JSON}';

    protected $description = 'Check the provisioning status of a project';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $logFile = "/tmp/provision-{$slug}.log";

        // Check if log file exists
        if (! file_exists($logFile)) {
            return $this->output('not_found', 'No provisioning log found for this project');
        }

        // Read log content
        $logContent = file_get_contents($logFile);

        // Check if provisioning is still running (process check)
        $isRunning = $this->isProvisionRunning($slug);

        // Determine status from log content
        $status = $this->parseStatus($logContent, $isRunning);
        $error = $this->parseError($logContent);

        return $this->output($status, null, $error);
    }

    private function isProvisionRunning(string $slug): bool
    {
        // Check if the provision process is running
        $launcherScript = "/tmp/launch-provision-{$slug}.sh";

        // Check for running process
        $output = shell_exec("pgrep -f 'provision.*{$slug}' 2>/dev/null");
        if (! empty(trim((string) $output))) {
            return true;
        }

        // Also check if log file was modified in last 30 seconds (active writing)
        $logFile = "/tmp/provision-{$slug}.log";
        if (file_exists($logFile)) {
            $mtime = filemtime($logFile);
            if ($mtime && (time() - $mtime) < 30) {
                // Log was recently modified, might still be running
                // Check log content for completion markers
                $content = file_get_contents($logFile);
                if (! str_contains($content, 'provisioned successfully') && ! str_contains($content, 'Provisioning failed')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function parseStatus(string $logContent, bool $isRunning): string
    {
        // Check for completion
        if (str_contains($logContent, 'provisioned successfully')) {
            return 'ready';
        }

        // Check for failure
        if (str_contains($logContent, 'Provisioning failed') || str_contains($logContent, 'Failed to')) {
            return 'failed';
        }

        // If not running and not complete, it failed silently
        if (! $isRunning) {
            return 'failed';
        }

        // Parse current step from log
        if (str_contains($logContent, 'Regenerating Caddy') || str_contains($logContent, 'Caddy reloaded')) {
            return 'finalizing';
        }
        if (str_contains($logContent, 'Registering project with orchestrator')) {
            return 'finalizing';
        }
        if (str_contains($logContent, 'Setup completed') || str_contains($logContent, 'Running project setup')) {
            return 'setting_up';
        }
        if (str_contains($logContent, 'Cloning repository') || str_contains($logContent, 'Repository cloned')) {
            return 'cloning';
        }
        if (str_contains($logContent, 'Creating GitHub repository') || str_contains($logContent, 'GitHub repository created')) {
            return 'creating_repo';
        }

        return 'provisioning';
    }

    private function parseError(string $logContent): ?string
    {
        // Extract error message if present
        if (preg_match('/Provisioning failed: (.+)$/m', $logContent, $matches)) {
            return $matches[1];
        }
        if (preg_match('/Failed to (.+)$/m', $logContent, $matches)) {
            return 'Failed to '.$matches[1];
        }

        return null;
    }

    private function output(string $status, ?string $message = null, ?string $error = null): int
    {
        $data = [
            'status' => $status,
            'error' => $error,
        ];

        if ($this->option('json')) {
            $this->line(json_encode(['success' => true, 'data' => $data], JSON_PRETTY_PRINT));
        } else {
            $this->info("Status: {$status}");
            if ($error) {
                $this->error("Error: {$error}");
            }
        }

        return $status === 'failed' ? 1 : 0;
    }
}
