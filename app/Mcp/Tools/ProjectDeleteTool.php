<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ProjectDeleteTool extends Tool
{
    protected string $description = 'Delete a project with cascade deletion of GitHub repo and sequence entry';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->required()->description('Project slug to delete'),
            'confirm' => $schema->boolean()->required()->description('Must be true to confirm deletion'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $slug = $request->get('slug');
        $confirm = $request->get('confirm');

        if (! $slug) {
            return Response::error('Project slug is required');
        }

        if ($confirm !== true) {
            return Response::error('Deletion not confirmed. Set confirm=true to proceed');
        }

        // Build the command
        $command = 'launchpad project:delete '.escapeshellarg((string) $slug).' --force --json';

        // Execute the command
        $result = Process::timeout(120)->run($command);

        if (! $result->successful()) {
            return Response::error(
                'Failed to delete project: '.($result->errorOutput() ?: $result->output())
            );
        }

        // Parse JSON response
        try {
            $output = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);

            if (isset($output['success']) && $output['success']) {
                return Response::structured([
                    'success' => true,
                    'slug' => $slug,
                    'message' => $output['data']['message'] ?? 'Project deleted successfully',
                    'steps' => $output['data']['steps'] ?? [],
                ]);
            }

            return Response::error($output['error'] ?? 'Unknown error occurred');
        } catch (\JsonException $e) {
            return Response::error('Failed to parse command output: '.$e->getMessage());
        }
    }
}
