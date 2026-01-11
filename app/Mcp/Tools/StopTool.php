<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\DockerManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class StopTool extends Tool
{
    protected string $name = 'launchpad_stop';

    protected string $description = 'Stop all Launchpad Docker services (DNS, PHP, Caddy, PostgreSQL, Redis, Mailpit, and enabled optional services)';

    public function __construct(
        protected DockerManager $dockerManager,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        try {
            $this->dockerManager->stopAll();

            return Response::structured([
                'success' => true,
                'message' => 'All Launchpad services stopped successfully',
            ]);
        } catch (\Exception $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
