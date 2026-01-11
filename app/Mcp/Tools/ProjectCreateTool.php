<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ProjectCreateTool extends Tool
{
    protected string $description = 'Create a new project with optional GitHub template';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Project name/slug'),
            'template' => $schema->string()->description('GitHub template repository (user/repo format)'),
            'visibility' => $schema->string()->enum(['private', 'public'])->description('Repository visibility'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $name = $request->get('name');
        $template = $request->get('template');
        $visibility = $request->get('visibility', 'private');

        if (! $name) {
            return Response::error('Project name is required');
        }

        // Build the command
        $command = 'launchpad project:create '.escapeshellarg($name);

        if ($template) {
            $command .= ' --template='.escapeshellarg($template);
        }

        $command .= ' --visibility='.escapeshellarg($visibility);
        $command .= ' --json';

        // Execute the command
        $result = Process::run($command);

        if (! $result->successful()) {
            return Response::error(
                'Failed to create project: '.($result->errorOutput() ?: $result->output())
            );
        }

        // Parse JSON response
        try {
            $output = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);

            if (isset($output['success']) && $output['success']) {
                return Response::structured([
                    'success' => true,
                    'project_slug' => $output['data']['project_slug'] ?? $name,
                    'status' => $output['data']['status'] ?? 'unknown',
                    'message' => $output['data']['message'] ?? 'Project created successfully',
                ]);
            }

            return Response::error($output['error'] ?? 'Unknown error occurred');
        } catch (\JsonException $e) {
            return Response::error('Failed to parse command output: '.$e->getMessage());
        }
    }
}
