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
}
