<?php

declare(strict_types=1);

namespace App\Data\Provision;

final class ProvisionContext
{
    public function __construct(
        public string $slug,
        public string $projectPath,
        public ?string $githubRepo = null,
        public ?string $cloneUrl = null,
        public ?string $template = null,
        public string $visibility = 'private',
        public ?string $phpVersion = null,
        public ?string $dbDriver = null,
        public ?string $sessionDriver = null,
        public ?string $cacheDriver = null,
        public ?string $queueDriver = null,
        public bool $minimal = false,
        public bool $fork = false,
        public ?string $displayName = null,
        public ?string $tld = 'ccc',
    ) {}

    public function getHomeDir(): string
    {
        return $_SERVER['HOME'] ?? '/home/launchpad';
    }

    public function getPhpEnv(): array
    {
        $home = $this->getHomeDir();

        return [
            'HOME' => $home,
            'PATH' => "{$home}/.config/herd-lite/bin:{$home}/.local/bin:/usr/local/bin:/usr/bin:/bin",
        ];
    }

    /**
     * Get the PATH string for clean environment commands.
     */
    public function getCleanPath(): string
    {
        $home = $this->getHomeDir();

        return "{$home}/.config/herd-lite/bin:{$home}/.local/bin:/usr/local/bin:/usr/bin:/bin";
    }

    /**
     * Wrap a command with env -i to prevent inherited environment variables
     * from overriding the project's .env file.
     *
     * This is necessary because phpdotenv (used by Laravel) does NOT override
     * existing environment variables. When running artisan commands from within
     * Horizon, the parent process's env vars would otherwise take precedence.
     */
    public function wrapWithCleanEnv(string $command): string
    {
        $home = $this->getHomeDir();
        $path = $this->getCleanPath();

        return "env -i HOME={$home} PATH={$path} {$command}";
    }
}
