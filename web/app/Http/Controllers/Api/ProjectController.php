<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateProjectJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /**
     * Create a new project by dispatching a job to Horizon.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:1|max:255',
            'template' => 'nullable|string',
            'clone_url' => 'nullable|string',
            'fork' => 'boolean',
            'visibility' => 'required|in:public,private',
            'php_version' => 'nullable|string|in:8.3,8.4,8.5',
            'db_driver' => 'nullable|string|in:mysql,pgsql,sqlite',
            'session_driver' => 'nullable|string|in:file,database,redis',
            'cache_driver' => 'nullable|string|in:file,database,redis',
            'queue_driver' => 'nullable|string|in:sync,database,redis',
            'path' => 'nullable|string',
            'minimal' => 'boolean',
            'organization' => 'nullable|string|max:255',
        ]);

        $slug = Str::slug($validated['name']);

        // Reject reserved name
        if (strtolower($slug) === 'orbit') {
            return response()->json([
                'success' => false,
                'error' => 'The name "orbit" is reserved for the system.',
            ], 422);
        }

        // Dispatch job to Horizon (runs on host, not in container)
        CreateProjectJob::dispatch(
            slug: $slug,
            template: $validated['template'] ?? null,
            cloneUrl: $validated['clone_url'] ?? null,
            fork: $validated['fork'] ?? false,
            visibility: $validated['visibility'],
            name: $validated['name'] !== $slug ? $validated['name'] : null,
            phpVersion: $validated['php_version'] ?? null,
            dbDriver: $validated['db_driver'] ?? null,
            sessionDriver: $validated['session_driver'] ?? null,
            cacheDriver: $validated['cache_driver'] ?? null,
            queueDriver: $validated['queue_driver'] ?? null,
            path: $validated['path'] ?? null,
            minimal: $validated['minimal'] ?? false,
            organization: $validated['organization'] ?? null,
        );

        return response()->json([
            'success' => true,
            'status' => 'provisioning',
            'slug' => $slug,
            'message' => 'Project provisioning started.',
        ], 202);
    }

    /**
     * Health check endpoint.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
        ]);
    }
}
