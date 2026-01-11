<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ConfigureLaravelEnvPrompt;
use App\Mcp\Prompts\SetupHorizonPrompt;
use App\Mcp\Resources\ConfigResource;
use App\Mcp\Resources\EnvTemplateResource;
use App\Mcp\Resources\InfrastructureResource;
use App\Mcp\Resources\SitesResource;
use App\Mcp\Tools\LogsTool;
use App\Mcp\Tools\PhpTool;
use App\Mcp\Tools\ProjectCreateTool;
use App\Mcp\Tools\ProjectDeleteTool;
use App\Mcp\Tools\RestartTool;
use App\Mcp\Tools\SitesTool;
use App\Mcp\Tools\StartTool;
use App\Mcp\Tools\StatusTool;
use App\Mcp\Tools\StopTool;
use App\Mcp\Tools\WorktreesTool;
use Laravel\Mcp\Server;

final class LaunchpadServer extends Server
{
    protected string $name = 'Launchpad';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'INSTRUCTIONS'
        # Launchpad Development Environment

        Launchpad is a Docker-based PHP development environment that provides a complete local development stack.

        ## Available Services

        All services run in Docker containers and are accessible from your projects:

        ### Database
        - **PostgreSQL**: `launchpad-postgres:5432`
          - User: `launchpad`
          - Password: `launchpad`
          - Each project gets its own database named after the project slug
          - Example: Project "my-app" → Database "my_app"

        ### Cache & Queue
        - **Redis**: `launchpad-redis:6379`
          - No password required
          - Available for cache, session, and queue drivers

        ### Mail
        - **Mailpit**: `launchpad-mailpit:1025` (SMTP)
          - Web UI: `http://mailpit.test:8025`
          - All outgoing emails are captured locally

        ### WebSocket
        - **Reverb**: `launchpad-reverb:6001`
          - Laravel WebSocket server
          - Used for real-time broadcasting

        ### Web Server
        - **Caddy**: Automatic HTTPS for `.test` domains
          - Projects accessible at `{slug}.test`
          - Automatic SSL certificate generation

        ### PHP
        - **PHP 8.3**: `launchpad-php-83:9000` (FPM)
        - **PHP 8.4**: `launchpad-php-84:9000` (FPM)
        - **PHP 8.5**: `launchpad-php-85:9000` (FPM)
        - Set per-project PHP version with `launchpad php` command

        ## IMPORTANT: Do NOT Install Services Locally

        **DO NOT** install PostgreSQL, Redis, or Mailpit locally on the host machine.
        All services are provided by Launchpad Docker containers.

        When configuring Laravel projects:
        - Use `DB_HOST=launchpad-postgres`
        - Use `REDIS_HOST=launchpad-redis`
        - Use `MAIL_HOST=launchpad-mailpit`

        ## Project Structure

        - Projects are stored in `~/projects/`
        - Each project is accessible at `https://{slug}.test`
        - Git worktrees create automatic subdomains: `{worktree}.{slug}.test`

        ## Environment Configuration

        For Laravel projects, use these settings in `.env`:

        ```env
        DB_CONNECTION=pgsql
        DB_HOST=launchpad-postgres
        DB_PORT=5432
        DB_DATABASE={slug_with_underscores}
        DB_USERNAME=launchpad
        DB_PASSWORD=launchpad

        REDIS_HOST=launchpad-redis
        REDIS_PORT=6379

        MAIL_MAILER=smtp
        MAIL_HOST=launchpad-mailpit
        MAIL_PORT=1025

        CACHE_STORE=redis
        SESSION_DRIVER=redis
        QUEUE_CONNECTION=redis
        ```

        ## Common Workflows

        1. **Create a new Laravel project**:
           - Use `launchpad_project_create` tool
           - Project will be provisioned automatically with correct database/cache settings

        2. **Set PHP version for a project**:
           - Use `launchpad_php` tool
           - Available versions: 8.3, 8.4, 8.5

        3. **View project logs**:
           - Use `launchpad_logs` tool
           - Available services: caddy, php-83, php-84, php-85, postgres, redis

        4. **Check service status**:
           - Use `launchpad_status` tool
           - Shows running containers and project count

        5. **Manage git worktrees**:
           - Use `launchpad_worktrees` tool
           - Worktrees are automatically detected and get subdomains
        INSTRUCTIONS;

    protected array $tools = [
        StatusTool::class,
        StartTool::class,
        StopTool::class,
        RestartTool::class,
        SitesTool::class,
        PhpTool::class,
        ProjectCreateTool::class,
        ProjectDeleteTool::class,
        LogsTool::class,
        WorktreesTool::class,
    ];

    protected array $resources = [
        InfrastructureResource::class,
        ConfigResource::class,
        EnvTemplateResource::class,
        SitesResource::class,
    ];

    protected array $prompts = [
        ConfigureLaravelEnvPrompt::class,
        SetupHorizonPrompt::class,
    ];
}
