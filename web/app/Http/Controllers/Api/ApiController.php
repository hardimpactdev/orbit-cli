<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class ApiController extends Controller
{
    /**
     * Execute a launchpad CLI command and return JSON response.
     */
    protected function executeCommand(string $command, array $args = [], int $timeout = 30): array
    {
        $launchpad = $this->findLaunchpadBinary();
        $home = $_SERVER['HOME'] ?? '/home/launchpad';

        // Build command string
        $cmd = "{$launchpad} {$command}";
        foreach ($args as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $cmd .= " --{$key}";
                }
            } elseif ($value !== null) {
                $cmd .= " --{$key}=".escapeshellarg($value);
            }
        }
        $cmd .= ' --json';

        try {
            $result = Process::timeout($timeout)
                ->env([
                    'HOME' => $home,
                    'PATH' => "{$home}/.local/bin:{$home}/.config/herd-lite/bin:/usr/local/bin:/usr/bin:/bin",
                ])
                ->run($cmd);

            if ($result->successful()) {
                $output = trim($result->output());
                $json = json_decode($output, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json;
                }

                return ['success' => true, 'output' => $output];
            }

            return [
                'success' => false,
                'error' => trim($result->errorOutput()) ?: 'Command failed',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Find the launchpad binary.
     */
    protected function findLaunchpadBinary(): string
    {
        $home = $_SERVER['HOME'] ?? '/home/launchpad';
        $paths = [
            // Container paths
            '/usr/local/bin/launchpad',
            // Host paths
            "{$home}/.local/bin/launchpad",
            '/usr/local/bin/launchpad',
            "{$home}/projects/launchpad-cli/launchpad",
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return '/usr/local/bin/launchpad';
    }

    // ===== Status & Info =====

    /**
     * Get launchpad status.
     */
    public function status(): JsonResponse
    {
        return response()->json($this->executeCommand('status'));
    }

    /**
     * Get all sites.
     */
    public function sites(): JsonResponse
    {
        return response()->json($this->executeCommand('sites'));
    }

    /**
     * Get all projects.
     */
    public function projects(): JsonResponse
    {
        return response()->json($this->executeCommand('project:list'));
    }

    /**
     * Get config.
     */
    public function config(): JsonResponse
    {
        // Get config from status command which already has access to it
        $status = $this->executeCommand('status');

        if (! ($status['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $status['error'] ?? 'Failed to get config',
            ], 500);
        }

        $data = $status['data'] ?? [];

        return response()->json([
            'success' => true,
            'tld' => $data['tld'] ?? 'test',
            'default_php_version' => $data['default_php_version'] ?? '8.3',
            'config_path' => $data['config_path'] ?? '',
        ]);
    }

    /**
     * Save config.
     * Note: Config changes require SSH access to the host.
     */
    public function saveConfig(Request $request): JsonResponse
    {
        // Config file is on the host, not accessible from container
        // Config changes should be done via SSH or the desktop app's settings
        return response()->json([
            'success' => false,
            'error' => 'Config changes must be made via SSH or desktop app settings',
        ], 501);
    }

    /**
     * Get available PHP versions.
     */
    public function phpVersions(): JsonResponse
    {
        // Get available PHP versions from running containers
        $result = Process::timeout(10)->run('docker ps --format "{{.Names}}" 2>/dev/null | grep launchpad-php');

        $versions = [];
        if ($result->successful()) {
            foreach (explode("\n", trim($result->output())) as $container) {
                if (preg_match('/launchpad-php-(\d+)$/', $container, $matches)) {
                    $version = $matches[1];
                    // Convert 83 to 8.3, 84 to 8.4, etc.
                    if (strlen($version) === 2) {
                        $versions[] = $version[0].'.'.$version[1];
                    }
                }
            }
        }

        // Fallback to common versions if docker command fails
        if (empty($versions)) {
            $versions = ['8.3', '8.4', '8.5'];
        }

        sort($versions);

        return response()->json([
            'success' => true,
            'versions' => $versions,
        ]);
    }

    // ===== Service Control =====

    /**
     * Start all services.
     */
    public function start(): JsonResponse
    {
        return response()->json($this->executeCommand('start', [], 120));
    }

    /**
     * Stop all services.
     */
    public function stop(): JsonResponse
    {
        return response()->json($this->executeCommand('stop', [], 60));
    }

    /**
     * Restart all services.
     */
    public function restart(): JsonResponse
    {
        return response()->json($this->executeCommand('restart', [], 120));
    }

    /**
     * Start a specific service.
     */
    public function startService(string $service): JsonResponse
    {
        $home = $_SERVER['HOME'] ?? '/home/launchpad';
        $result = Process::timeout(30)
            ->env([
                'HOME' => $home,
                'PATH' => "{$home}/.local/bin:/usr/local/bin:/usr/bin:/bin",
            ])
            ->run("docker start {$service}");

        return response()->json([
            'success' => $result->successful(),
            'error' => $result->successful() ? null : trim($result->errorOutput()),
        ]);
    }

    /**
     * Stop a specific service.
     */
    public function stopService(string $service): JsonResponse
    {
        $home = $_SERVER['HOME'] ?? '/home/launchpad';
        $result = Process::timeout(30)
            ->env([
                'HOME' => $home,
                'PATH' => "{$home}/.local/bin:/usr/local/bin:/usr/bin:/bin",
            ])
            ->run("docker stop {$service}");

        return response()->json([
            'success' => $result->successful(),
            'error' => $result->successful() ? null : trim($result->errorOutput()),
        ]);
    }

    /**
     * Restart a specific service.
     */
    public function restartService(string $service): JsonResponse
    {
        $home = $_SERVER['HOME'] ?? '/home/launchpad';
        $result = Process::timeout(60)
            ->env([
                'HOME' => $home,
                'PATH' => "{$home}/.local/bin:/usr/local/bin:/usr/bin:/bin",
            ])
            ->run("docker restart {$service}");

        return response()->json([
            'success' => $result->successful(),
            'error' => $result->successful() ? null : trim($result->errorOutput()),
        ]);
    }

    /**
     * Get service logs.
     */
    public function serviceLogs(Request $request, string $service): JsonResponse
    {
        $lines = min($request->input('lines', 100), 1000);
        $home = $_SERVER['HOME'] ?? '/home/launchpad';

        $result = Process::timeout(30)
            ->env([
                'HOME' => $home,
                'PATH' => "{$home}/.local/bin:/usr/local/bin:/usr/bin:/bin",
            ])
            ->run("docker logs --tail {$lines} {$service} 2>&1");

        return response()->json([
            'success' => true,
            'logs' => $result->output(),
        ]);
    }

    // ===== PHP Management =====

    /**
     * Get PHP version for a site.
     */
    public function getPhp(string $site): JsonResponse
    {
        return response()->json($this->executeCommand('php', ['site' => $site]));
    }

    /**
     * Set PHP version for a site.
     */
    public function setPhp(Request $request, string $site): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'required|string|in:8.1,8.2,8.3,8.4,8.5',
        ]);

        return response()->json($this->executeCommand('php', [
            'site' => $site,
            'version' => $validated['version'],
        ]));
    }

    /**
     * Reset PHP version for a site.
     */
    public function resetPhp(string $site): JsonResponse
    {
        return response()->json($this->executeCommand('php', [
            'site' => $site,
            'reset' => true,
        ]));
    }

    // ===== Projects =====

    /**
     * Get project provision status.
     */
    public function provisionStatus(string $slug): JsonResponse
    {
        return response()->json($this->executeCommand('provision:status', ['project' => $slug]));
    }

    /**
     * Delete a project.
     */
    public function deleteProject(string $slug): JsonResponse
    {
        return response()->json($this->executeCommand('project:delete', ['project' => $slug]));
    }

    /**
     * Rebuild/update a project.
     */
    public function rebuildProject(string $slug): JsonResponse
    {
        return response()->json($this->executeCommand('project:update', ['project' => $slug], 300));
    }

    // ===== Worktrees =====

    /**
     * Get all worktrees.
     */
    public function worktrees(): JsonResponse
    {
        return response()->json($this->executeCommand('worktrees'));
    }

    /**
     * Get worktrees for a specific site.
     */
    public function siteWorktrees(string $site): JsonResponse
    {
        return response()->json($this->executeCommand('worktrees', ['site' => $site]));
    }

    /**
     * Refresh worktrees.
     */
    public function refreshWorktrees(): JsonResponse
    {
        return response()->json($this->executeCommand('worktree:refresh'));
    }

    /**
     * Unlink a worktree.
     */
    public function unlinkWorktree(string $site, string $name): JsonResponse
    {
        return response()->json($this->executeCommand('worktree:unlink', [
            'site' => $site,
            'name' => $name,
        ]));
    }

    // ===== Workspaces =====

    /**
     * Get all workspaces.
     */
    public function workspaces(): JsonResponse
    {
        return response()->json($this->executeCommand('workspaces'));
    }

    /**
     * Create a workspace.
     */
    public function createWorkspace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        return response()->json($this->executeCommand('workspace:create', [
            'name' => $validated['name'],
        ]));
    }

    /**
     * Delete a workspace.
     */
    public function deleteWorkspace(string $name): JsonResponse
    {
        return response()->json($this->executeCommand('workspace:delete', ['name' => $name]));
    }

    /**
     * Add project to workspace.
     */
    public function addWorkspaceProject(Request $request, string $workspace): JsonResponse
    {
        $validated = $request->validate([
            'project' => 'required|string',
        ]);

        return response()->json($this->executeCommand('workspace:add', [
            'workspace' => $workspace,
            'project' => $validated['project'],
        ]));
    }

    /**
     * Remove project from workspace.
     */
    public function removeWorkspaceProject(string $workspace, string $project): JsonResponse
    {
        return response()->json($this->executeCommand('workspace:remove', [
            'workspace' => $workspace,
            'project' => $project,
        ]));
    }

    // ===== Package Linking =====

    /**
     * Get linked packages for an app.
     */
    public function linkedPackages(string $app): JsonResponse
    {
        return response()->json($this->executeCommand('package:linked', ['app' => $app]));
    }

    /**
     * Link a package.
     */
    public function linkPackage(Request $request, string $app): JsonResponse
    {
        $validated = $request->validate([
            'package' => 'required|string',
        ]);

        return response()->json($this->executeCommand('package:link', [
            'app' => $app,
            'package' => $validated['package'],
        ]));
    }

    /**
     * Unlink a package.
     */
    public function unlinkPackage(string $app, string $package): JsonResponse
    {
        return response()->json($this->executeCommand('package:unlink', [
            'app' => $app,
            'package' => $package,
        ]));
    }

    // ===== GitHub =====
    // Note: GitHub CLI (gh) is not available in the container.
    // These operations should be done via SSH to the host.

    /**
     * Get GitHub user info.
     * Note: Requires SSH access - gh CLI not available in container.
     */
    public function githubUser(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'GitHub operations require SSH access to the host',
        ], 501);
    }

    /**
     * Check if a GitHub repo exists.
     * Note: Requires SSH access - gh CLI not available in container.
     */
    public function checkRepo(string $owner, string $repo): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'GitHub operations require SSH access to the host',
        ], 501);
    }
}
