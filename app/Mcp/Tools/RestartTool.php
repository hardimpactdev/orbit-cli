<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\DockerManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class RestartTool extends Tool
{
    protected string $name = 'launchpad_restart';

    protected string $description = 'Restart all Launchpad Docker services (stops all services, then starts them again)';

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
            $this->dockerManager->startAll();

            return Response::structured([
                'success' => true,
                'message' => 'All Launchpad services restarted successfully',
            ]);
        } catch (\Exception $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
