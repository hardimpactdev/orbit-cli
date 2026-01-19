<?php

declare(strict_types=1);

namespace App\Services;

use Pusher\Pusher;

final class ReverbBroadcaster
{
    private ?Pusher $pusher = null;

    private readonly bool $enabled;

    private string $host = '';

    private int $port = 0;

    public function __construct(ConfigManager $config)
    {
        $reverbConfig = $config->getReverbConfig();
        $this->enabled = $reverbConfig['enabled'];

        if ($this->enabled) {
            // Detect if running inside Docker container
            $isDocker = $this->isRunningInDocker();

            if ($isDocker) {
                // Inside Docker: connect to Reverb container via Docker network
                $this->host = getenv('REVERB_HOST') ?: 'orbit-reverb';
                $this->port = (int) (getenv('REVERB_PORT') ?: 8080);
            } else {
                // On host: connect to Reverb internal port (8080 is Laravel Reverb's default)
                $this->host = '127.0.0.1';
                $this->port = $reverbConfig['internal_port'] ?? 8080;
            }

            $this->pusher = new Pusher(
                $reverbConfig['app_key'],
                $reverbConfig['app_secret'],
                $reverbConfig['app_id'],
                [
                    'host' => $this->host,
                    'port' => $this->port,
                    'scheme' => 'http',
                    'useTLS' => false,
                ]
            );
        }
    }

    /**
     * Detect if we are running inside a Docker container.
     */
    private function isRunningInDocker(): bool
    {
        // Check for .dockerenv file (created by Docker)
        if (file_exists('/.dockerenv')) {
            return true;
        }

        // Check for container-specific cgroup
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if (str_contains($cgroup, 'docker') || str_contains($cgroup, 'kubepods')) {
                return true;
            }
        }

        // Check for REDIS_HOST env var (set in our Horizon container)
        if (getenv('REDIS_HOST') === 'orbit-redis') {
            return true;
        }

        return false;
    }

    /**
     * Broadcast an event to a channel via Reverb Pusher-compatible API.
     */
    public function broadcast(string $channel, string $event, array $data): void
    {
        // Debug logging
        $home = $_SERVER['HOME'] ?? '/home/orbit';
        $debugLog = "{$home}/.config/orbit/logs/reverb-debug.log";
        $timestamp = date('Y-m-d H:i:s');

        $debug = "[{$timestamp}] broadcast() called: channel={$channel} event={$event}\n";
        $debug .= "  enabled={$this->enabled} host={$this->host} port={$this->port}\n";
        $debug .= '  pusher='.($this->pusher ? 'yes' : 'no')."\n";
        $debug .= '  data='.json_encode($data)."\n";

        if (! $this->enabled || ! $this->pusher) {
            $debug .= "  SKIPPED: not enabled or no pusher\n";
            @file_put_contents($debugLog, $debug, FILE_APPEND);

            return;
        }

        try {
            $result = $this->pusher->trigger($channel, $event, $data);
            $debug .= '  SENT: result='.json_encode($result)."\n";
        } catch (\Throwable $e) {
            $debug .= '  ERROR: '.$e->getMessage()."\n";
            error_log('Reverb broadcast failed: '.$e->getMessage());
        }

        @file_put_contents($debugLog, $debug, FILE_APPEND);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
