<?php

declare(strict_types=1);

namespace App\Services;

use Pusher\Pusher;

final class ReverbBroadcaster
{
    private ?Pusher $pusher = null;

    private readonly bool $enabled;

    public function __construct(ConfigManager $config)
    {
        $reverbConfig = $config->getReverbConfig();
        $this->enabled = $reverbConfig['enabled'];

        if ($this->enabled) {
            // For server-side broadcasting, connect to Reverb's internal port (6001)
            // This avoids TLS issues when broadcasting from the same server
            $internalPort = $reverbConfig['internal_port'] ?? 6001;

            $this->pusher = new Pusher(
                $reverbConfig['app_key'],
                $reverbConfig['app_secret'],
                $reverbConfig['app_id'],
                [
                    'host' => '127.0.0.1',
                    'port' => $internalPort,
                    'scheme' => 'http',
                    'useTLS' => false,
                ]
            );
        }
    }

    /**
     * Broadcast an event to a channel via Reverb's Pusher-compatible API.
     *
     * @param  string  $channel  Channel name (e.g., 'provisioning', 'project.my-app')
     * @param  string  $event  Event name (e.g., 'project.provision.status')
     * @param  array<string, mixed>  $data  Event payload
     */
    public function broadcast(string $channel, string $event, array $data): void
    {
        if (! $this->enabled || ! $this->pusher) {
            return;
        }

        try {
            $this->pusher->trigger($channel, $event, $data);
        } catch (\Throwable $e) {
            error_log('Reverb broadcast failed: '.$e->getMessage());
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
