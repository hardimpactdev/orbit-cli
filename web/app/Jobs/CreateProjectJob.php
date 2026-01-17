<?php

namespace App\Jobs;

use App\Events\ProjectProvisionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class CreateProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120; // 2 minutes max

    public function __construct(
        public string $slug,
        public ?string $template = null,
        public ?string $cloneUrl = null,
        public bool $fork = false,
        public string $visibility = 'private',
        public ?string $name = null,
        public ?string $phpVersion = null,
        public ?string $dbDriver = null,
        public ?string $sessionDriver = null,
        public ?string $cacheDriver = null,
        public ?string $queueDriver = null,
        public ?string $path = null,
        public bool $minimal = false,
        public ?string $organization = null,
    ) {}

    public function handle(): void
    {
        $orbit = $this->findOrbitBinary();
        $home = $_SERVER['HOME'] ?? '/home/orbit';

        try {
            $this->broadcast('provisioning');

            // Build the provision command
            $command = "{$orbit} provision ".escapeshellarg($this->slug);

            if ($this->template) {
                $command .= ' --template='.escapeshellarg($this->template);
            }
            if ($this->cloneUrl) {
                $command .= ' --clone-url='.escapeshellarg($this->cloneUrl);
            }
            if ($this->fork) {
                $command .= ' --fork';
            }
            $command .= ' --visibility='.escapeshellarg($this->visibility);
            if ($this->name && $this->name !== $this->slug) {
                $command .= ' --name='.escapeshellarg($this->name);
            }
            if ($this->phpVersion) {
                $command .= ' --php='.escapeshellarg($this->phpVersion);
            }
            if ($this->dbDriver) {
                $command .= ' --db-driver='.escapeshellarg($this->dbDriver);
            }
            if ($this->sessionDriver) {
                $command .= ' --session-driver='.escapeshellarg($this->sessionDriver);
            }
            if ($this->cacheDriver) {
                $command .= ' --cache-driver='.escapeshellarg($this->cacheDriver);
            }
            if ($this->queueDriver) {
                $command .= ' --queue-driver='.escapeshellarg($this->queueDriver);
            }
            if ($this->minimal) {
                $command .= ' --minimal';
            }
            if ($this->organization) {
                $command .= ' --organization='.escapeshellarg($this->organization);
            }

            Log::info('CreateProjectJob: Running', ['slug' => $this->slug, 'command' => $command]);

            $result = Process::timeout($this->timeout - 10)
                ->env([
                    'HOME' => $home,
                    'PATH' => "{$home}/.bun/bin:{$home}/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin",
                ])
                ->run($command);

            if (! $result->successful()) {
                $error = $result->errorOutput() ?: $result->output();
                Log::error('CreateProjectJob: Failed', ['slug' => $this->slug, 'error' => substr($error, 0, 1000)]);
                throw new \RuntimeException('Provision failed: '.substr($error, 0, 500));
            }

            Log::info('CreateProjectJob: Completed', ['slug' => $this->slug]);
            $this->broadcast('ready');

        } catch (\Exception $e) {
            Log::error('CreateProjectJob: Exception', ['slug' => $this->slug, 'error' => $e->getMessage()]);
            $this->broadcast('failed', $e->getMessage());
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->broadcast('failed', $exception?->getMessage() ?? 'Unknown error');
    }

    private function findOrbitBinary(): string
    {
        $home = $_SERVER['HOME'] ?? '/home/orbit';
        $paths = ["{$home}/.local/bin/orbit", '/usr/local/bin/orbit', "{$home}/projects/orbit-cli/orbit"];
        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return "{$home}/.local/bin/orbit";
    }

    private function broadcast(string $status, ?string $error = null): void
    {
        try {
            event(new ProjectProvisionStatus(slug: $this->slug, status: $status, error: $error));
        } catch (\Throwable $e) {
            Log::warning('CreateProjectJob: Broadcast failed', ['slug' => $this->slug, 'status' => $status]);
        }
    }
}
