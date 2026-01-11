<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\WorktreeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class WorktreesTool extends Tool
{
    protected string $description = 'List git worktrees with their subdomains';

    public function __construct(
        protected WorktreeService $worktreeService,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Filter to a specific site (optional - returns all if omitted)'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $site = $request->get('site');

        if ($site) {
            // Get worktrees for specific site
            $worktrees = $this->worktreeService->getSiteWorktrees($site);

            return Response::structured([
                'site' => $site,
                'worktrees' => $worktrees,
                'count' => count($worktrees),
            ]);
        }

        // Get all worktrees
        $worktrees = $this->worktreeService->getAllWorktrees();

        // Group by site for better organization
        $groupedWorktrees = [];
        foreach ($worktrees as $worktree) {
            $siteName = $worktree['site'];
            if (! isset($groupedWorktrees[$siteName])) {
                $groupedWorktrees[$siteName] = [];
            }
            $groupedWorktrees[$siteName][] = $worktree;
        }

        return Response::structured([
            'worktrees' => $worktrees,
            'count' => count($worktrees),
            'sites_with_worktrees' => count($groupedWorktrees),
            'grouped_by_site' => $groupedWorktrees,
        ]);
    }
}
