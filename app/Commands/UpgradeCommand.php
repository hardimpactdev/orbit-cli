<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use LaravelZero\Framework\Commands\Command;

class UpgradeCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'upgrade
        {--check : Only check for updates without installing}
        {--json : Output as JSON}';

    protected $description = 'Upgrade Launchpad to the latest version';

    private const GITHUB_API_URL = 'https://api.github.com/repos/nckrtl/launchpad-cli/releases/latest';

    public function handle(): int
    {
        $currentVersion = config('app.version');
        $pharPath = \Phar::running(false);

        // Check if running as PHAR
        if (empty($pharPath) && ! $this->option('check')) {
            return $this->handleError(
                'Upgrade is only available when running as a compiled PHAR binary.',
                ExitCode::GeneralError
            );
        }

        // Fetch latest release info
        $release = $this->fetchLatestRelease();
        if ($release === null) {
            return $this->handleError(
                'Failed to fetch release information from GitHub.',
                ExitCode::GeneralError
            );
        }

        $latestVersion = $release['tag_name'];
        $isUpToDate = $this->isUpToDate($currentVersion, $latestVersion);

        // Check-only mode
        if ($this->option('check')) {
            return $this->handleCheckResult($currentVersion, $latestVersion, $isUpToDate);
        }

        // Already up to date
        if ($isUpToDate) {
            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'action' => 'upgrade',
                    'current_version' => $currentVersion,
                    'latest_version' => $latestVersion,
                    'upgraded' => false,
                    'message' => 'Already up to date.',
                ]);
            }

            $this->info("You are already running the latest version ({$latestVersion}).");

            return self::SUCCESS;
        }

        // Find the PHAR download URL
        $downloadUrl = $this->findPharDownloadUrl($release);
        if ($downloadUrl === null) {
            return $this->handleError(
                'Could not find PHAR download URL in the release.',
                ExitCode::GeneralError
            );
        }

        // Download and install
        if (! $this->wantsJson()) {
            $this->info("Upgrading from {$currentVersion} to {$latestVersion}...");
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'launchpad_');
        if ($tempFile === false) {
            return $this->handleError(
                'Failed to create temporary file.',
                ExitCode::GeneralError
            );
        }

        try {
            // Download the new version
            if (! $this->downloadFile($downloadUrl, $tempFile)) {
                return $this->handleError(
                    'Failed to download the new version.',
                    ExitCode::GeneralError
                );
            }

            // Verify it's a valid PHAR
            if (! $this->isValidPhar($tempFile)) {
                return $this->handleError(
                    'Downloaded file is not a valid PHAR.',
                    ExitCode::GeneralError
                );
            }

            // Replace the current binary
            if (! $this->replaceCurrentBinary($pharPath, $tempFile)) {
                return $this->handleError(
                    'Failed to replace the current binary. You may need to run with sudo.',
                    ExitCode::GeneralError
                );
            }

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'action' => 'upgrade',
                    'previous_version' => $currentVersion,
                    'new_version' => $latestVersion,
                    'upgraded' => true,
                ]);
            }

            $this->info("Successfully upgraded to {$latestVersion}!");

            return self::SUCCESS;
        } finally {
            // Clean up temp file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatestRelease(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: launchpad-cli\r\n",
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);
        if ($response === false) {
            return null;
        }

        /** @var array<string, mixed>|null */
        $data = json_decode($response, true);

        return is_array($data) ? $data : null;
    }

    private function isUpToDate(string $currentVersion, string $latestVersion): bool
    {
        // Remove 'v' prefix for comparison
        $current = ltrim($currentVersion, 'v');
        $latest = ltrim($latestVersion, 'v');

        // Handle @version@ placeholder (development mode)
        if ($current === '@version@') {
            return false;
        }

        return version_compare($current, $latest, '>=');
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function findPharDownloadUrl(array $release): ?string
    {
        /** @var array<int, array<string, mixed>> $assets */
        $assets = $release['assets'] ?? [];

        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if ($name === 'launchpad.phar') {
                return $asset['browser_download_url'] ?? null;
            }
        }

        return null;
    }

    private function downloadFile(string $url, string $destination): bool
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: launchpad-cli\r\n",
                'timeout' => 120,
                'follow_location' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            return false;
        }

        return file_put_contents($destination, $content) !== false;
    }

    private function isValidPhar(string $path): bool
    {
        // Read the first 1KB to check for phar signature
        $content = @file_get_contents($path, false, null, 0, 1024);
        if ($content === false) {
            return false;
        }

        // Check for PHP shebang and phar indicators
        if (! str_contains($content, '<?php')) {
            return false;
        }

        // Check for __HALT_COMPILER which is required in all phars
        // We need to check the full file for this
        $fullContent = @file_get_contents($path);
        if ($fullContent === false) {
            return false;
        }

        return str_contains($fullContent, '__HALT_COMPILER()');
    }

    private function replaceCurrentBinary(string $currentPath, string $newPath): bool
    {
        // Make the new file executable
        if (! @chmod($newPath, 0755)) {
            return false;
        }

        // Backup current binary
        $backupPath = $currentPath.'.bak';
        if (! @copy($currentPath, $backupPath)) {
            return false;
        }

        // Replace with new binary
        if (! @rename($newPath, $currentPath)) {
            // Restore backup on failure
            @rename($backupPath, $currentPath);

            return false;
        }

        // Remove backup
        @unlink($backupPath);

        return true;
    }

    private function handleCheckResult(string $currentVersion, string $latestVersion, bool $isUpToDate): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'action' => 'check',
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'up_to_date' => $isUpToDate,
                'update_available' => ! $isUpToDate,
            ]);
        }

        $this->info("Current version: {$currentVersion}");
        $this->info("Latest version:  {$latestVersion}");

        if ($isUpToDate) {
            $this->info('You are up to date!');
        } else {
            $this->warn('An update is available. Run `launchpad upgrade` to install.');
        }

        return self::SUCCESS;
    }

    private function handleError(string $message, ExitCode $exitCode): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonError($message, $exitCode->value);
        }

        $this->error($message);

        return $exitCode->value;
    }
}
