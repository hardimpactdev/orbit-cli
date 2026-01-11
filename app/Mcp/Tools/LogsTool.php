<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\DockerManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class LogsTool extends Tool
{
    protected string $description = 'Get service logs from Docker containers';

    public function __construct(
        protected DockerManager $dockerManager,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()->required()->description('Container name (e.g., launchpad-caddy, launchpad-php-83)'),
            'lines' => $schema->integer()->default(100)->min(1)->max(1000)->description('Number of lines to retrieve (1-1000)'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $service = $request->get('service');
        $lines = $request->get('lines', 100);

        if (! $service) {
            return Response::error('Service/container name is required');
        }

        // Validate lines
        if ($lines < 1 || $lines > 1000) {
            return Response::error('Lines must be between 1 and 1000');
        }

        // Check if container is running
        if (! $this->dockerManager->isRunning($service)) {
            return Response::error("Container is not running: {$service}");
        }

        // Get logs using docker command
        $result = Process::run("docker logs --tail {$lines} {$service} 2>&1");

        if (! $result->successful()) {
            return Response::error(
                'Failed to retrieve logs: '.($result->errorOutput() ?: 'Unknown error')
            );
        }

        $output = $result->output();

        return Response::structured([
            'service' => $service,
            'lines_requested' => $lines,
            'logs' => $output,
            'lines_returned' => substr_count($output, "\n"),
        ]);
    }
}
