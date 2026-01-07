# Launchpad CLI

A Laravel Zero CLI tool for managing local PHP development environments using Docker containers.

## Project Overview

Launchpad sets up a complete local development environment with:
- **Caddy** - Web server with automatic HTTPS (TLS internal)
- **PHP 8.3 & 8.4** - Multiple PHP versions via PHP-FPM containers
- **PostgreSQL** - Database server
- **Redis** - Cache and session store
- **Mailpit** - Local mail testing
- **DNS** - Local DNS resolver for `.test` domains

## Architecture

```
app/
├── Commands/          # CLI commands (Laravel Zero)
│   ├── InitCommand.php          # Initialize launchpad configuration
│   ├── StartCommand.php         # Start all Docker services
│   ├── StopCommand.php          # Stop all Docker services
│   ├── RestartCommand.php       # Restart all services
│   ├── StatusCommand.php        # Show service status
│   ├── SitesCommand.php         # List registered sites
│   ├── PhpCommand.php           # Set PHP version per site
│   ├── LogsCommand.php          # View service logs
│   ├── TrustCommand.php         # Trust the local CA certificate
│   ├── UpgradeCommand.php       # Self-update to latest version
│   ├── RebuildCommand.php       # Rebuild PHP images with extensions
│   ├── WorktreesCommand.php     # List git worktrees with subdomains
│   ├── WorktreeRefreshCommand.php   # Refresh/auto-link worktrees
│   ├── WorktreeUnlinkCommand.php    # Unlink worktree from site
│   ├── ProjectCreateCommand.php     # Create project with provisioning
│   ├── ProjectListCommand.php       # List all projects
│   ├── ProjectScanCommand.php       # Scan for git repos in paths
│   ├── ProjectUpdateCommand.php     # Update project (pull + deps)
│   ├── ProjectDeleteCommand.php     # Delete project with cascade
│   ├── ProvisionCommand.php         # Background provisioning
│   ├── ProvisionStatusCommand.php   # Check provisioning status
│   ├── ConfigMigrateCommand.php     # Migrate config to SQLite
│   └── ReverbSetupCommand.php       # Setup Reverb WebSocket
├── Concerns/
│   └── WithJsonOutput.php    # Trait for JSON output support
├── Enums/
│   └── ExitCode.php          # Standardized exit codes
├── Providers/
│   └── AppServiceProvider.php
└── Services/
    ├── CaddyfileGenerator.php   # Generates Caddyfile configuration
    ├── ConfigManager.php        # Manages user configuration
    ├── DockerManager.php        # Docker container operations
    ├── PhpComposeGenerator.php  # Generates PHP docker-compose
    ├── SiteScanner.php          # Scans paths for PHP projects
    ├── WorktreeService.php      # Git worktree management
    ├── DatabaseService.php      # SQLite for PHP overrides
    ├── McpClient.php            # MCP client for orchestrator
    └── ReverbBroadcaster.php    # WebSocket broadcasting
```

## Commands

All commands support `--json` flag for machine-readable output.

| Command | Description |
|---------|-------------|
| `launchpad init` | Initialize configuration |
| `launchpad start` | Start all services |
| `launchpad stop` | Stop all services |
| `launchpad restart` | Restart all services |
| `launchpad status` | Show service status |
| `launchpad sites` | List all sites |
| `launchpad php <site> <version>` | Set PHP version for a site |
| `launchpad php <site> --reset` | Reset to default PHP version |
| `launchpad logs [service]` | View service logs |
| `launchpad trust` | Trust the local CA certificate |
| `launchpad upgrade` | Upgrade to the latest version |
| `launchpad upgrade --check` | Check for available updates |
| `launchpad worktrees [site]` | List git worktrees with subdomains |
| `launchpad worktree:refresh` | Refresh and auto-link new worktrees |
| `launchpad worktree:unlink <site> <worktree>` | Unlink worktree from site |
| `launchpad project:list` | List all directories in scan paths |
| `launchpad project:scan` | Scan for git repos in configured paths |
| `launchpad project:update [path]` | Update project (git pull + deps) |
| `launchpad project:delete <slug>` | Delete project with cascade |
| `launchpad provision:status <slug>` | Check provisioning status |
| `launchpad config:migrate` | Migrate config.json to SQLite |
| `launchpad reverb:setup` | Setup Reverb WebSocket service |

## JSON Output Format

All commands with `--json` flag return structured JSON:

### Success Response
```json
{
  "success": true,
  "data": {
    // Command-specific data
  }
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error message here"
}
```

### Sites JSON Structure
```json
{
  "success": true,
  "data": {
    "sites": [
      {
        "name": "mysite",
        "domain": "mysite.test",
        "path": "/home/user/projects/mysite",
        "php_version": "8.3",
        "has_custom_php": false,
        "secure": true
      }
    ],
    "default_php_version": "8.3",
    "sites_count": 1
  }
}
```

### Status JSON Structure
```json
{
  "success": true,
  "data": {
    "running": true,
    "services": {
      "dns": { "status": "running", "container": "launchpad-dns" },
      "php-83": { "status": "running", "container": "launchpad-php-83" },
      "php-84": { "status": "running", "container": "launchpad-php-84" },
      "caddy": { "status": "running", "container": "launchpad-caddy" },
      "postgres": { "status": "running", "container": "launchpad-postgres" },
      "redis": { "status": "running", "container": "launchpad-redis" },
      "mailpit": { "status": "running", "container": "launchpad-mailpit" }
    },
    "services_running": 7,
    "services_total": 7,
    "sites_count": 2,
    "config_path": "/home/user/.config/launchpad",
    "tld": "test",
    "default_php_version": "8.3"
  }
}
```

## Git Worktree Support

Launchpad automatically detects git worktrees and creates subdomains for them:

- Worktrees are accessible via `<worktree>.<site>.test` (e.g., `feature-auth.myapp.test`)
- Run `worktree:refresh` after creating new worktrees to update Caddy routing
- Worktrees inherit the parent site's PHP version

## Exit Codes

Defined in `App\Enums\ExitCode`:

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General error |
| 2 | Invalid arguments |
| 3 | Docker not running |
| 4 | Service failed to start |
| 5 | Configuration error |

## Key Patterns

### Adding JSON Support to Commands

Use the `WithJsonOutput` trait:

```php
use App\Concerns\WithJsonOutput;

class MyCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'mycommand {--json : Output as JSON}';

    public function handle(): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'key' => 'value',
            ]);
        }

        // Human-readable output...
        return self::SUCCESS;
    }
}
```

### Error Handling with JSON

```php
if ($error) {
    if ($this->wantsJson()) {
        return $this->outputJsonError('Something went wrong', ExitCode::GeneralError->value);
    }
    $this->error('Something went wrong');
    return self::FAILURE;
}
```

## Configuration

User config is stored at `~/.config/launchpad/config.json`:

```json
{
  "paths": ["/home/user/projects"],
  "tld": "test",
  "default_php_version": "8.3"
}
```

## Data Storage

### SQLite Database

PHP version overrides and project metadata are stored in SQLite at `~/.config/launchpad/database.sqlite`:

```sql
CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    slug VARCHAR(255) UNIQUE,
    path VARCHAR(500),
    php_version VARCHAR(10) NULL,
    created_at DATETIME,
    updated_at DATETIME
)
```

Use `config:migrate` to migrate legacy `sites` overrides from config.json to SQLite.

### MCP Integration

The CLI communicates with the orchestrator via `McpClient` for project management operations. Configure the orchestrator URL in config.json:

```json
{
  "orchestrator": {
    "url": "http://localhost:8000"
  }
}
```

The MCP client handles `.ccc` TLD resolution by mapping to localhost, ensuring background processes work without DNS access.

## Docker Containers

| Container | Purpose |
|-----------|---------|
| `launchpad-dns` | Local DNS resolver |
| `launchpad-php-83` | PHP 8.3 FPM |
| `launchpad-php-84` | PHP 8.4 FPM |
| `launchpad-caddy` | Web server |
| `launchpad-postgres` | PostgreSQL database |
| `launchpad-redis` | Redis cache |
| `launchpad-mailpit` | Mail catcher |

## Development

### Running the CLI
```bash
# From project root
php launchpad <command>

# Or with executable
./launchpad <command>
```

### Quality Tools

| Tool | Command | Description |
|------|---------|-------------|
| PHPStan (Larastan) | `./vendor/bin/phpstan analyse` | Static analysis at level 5 |
| Rector | `./vendor/bin/rector` | Automated refactoring (PHP 8.2 rules) |
| Pint | `./vendor/bin/pint` | Laravel code style formatting |
| Pest | `./vendor/bin/pest` | Test suite (41 tests, 128 assertions) |

### Running All Checks
```bash
./vendor/bin/rector --dry-run
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pest
```

### Test Coverage

Tests are located in `tests/` directory:

| Category | Tests |
|----------|-------|
| Commands | StatusCommand, SitesCommand, PhpCommand, StartCommand, StopCommand, RestartCommand, LogsCommand, UpgradeCommand |
| Services | SiteScanner, ConfigManager, CaddyfileGenerator, PhpComposeGenerator |
| Enums | ExitCode |

### Git Hooks

Pre-commit hook runs all quality checks. Enable with:
```bash
git config core.hooksPath .githooks
```

### CI/CD

- **CI Workflow** (`.github/workflows/ci.yml`) - Runs on push/PR to main: Pint, Rector, PHPStan, Pest
- **Release Workflow** (`.github/workflows/release.yml`) - Builds PHAR on tag push

### Testing JSON Output
```bash
php launchpad status --json | jq .
php launchpad sites --json | jq '.data.sites'
```

## Claude Code Integration

### Hooks

The project includes Claude Code hooks (`.claude/hooks/php-checks.sh`) that automatically run when PHP files are modified:

1. **Rector** - Applies automated refactoring
2. **Pint** - Formats code
3. **PHPStan** - Static analysis
4. **Pest** - Runs tests
5. **Log check** - Scans `laravel.log` for new errors

### Skills

Available Claude Code skills in `.claude/skills/`:

- **release-version** - Automates version releases using `gh` CLI

## Integration with Desktop App

This CLI is designed to be controlled by the **launchpad-desktop** NativePHP application. The desktop app communicates via:

- **Local execution:** `Process::run('launchpad status --json')`
- **Remote execution:** `ssh user@host "cd ~/projects/launchpad && php launchpad status --json"`

The `--json` flag ensures machine-readable output for programmatic control.


## Project Provisioning

### Commands

| Command | Description |
|---------|-------------|
| `project:create` | Create a new project with async provisioning |
| `provision` | Background command that provisions a project |

### project:create

Creates a new project placeholder and starts background provisioning:

```bash
launchpad project:create my-app \
  --template=user/repo \
  --visibility=private \
  --json
```

**Options:**
- `--template` - GitHub template repository (user/repo format)
- `--clone-url` - Existing repo URL to clone (alternative to template)
- `--visibility` - Repository visibility: private (default) or public

**Response:**
```json
{
  "success": true,
  "data": {
    "project_slug": "my-app",
    "status": "provisioning",
    "message": "Project provisioning started in background"
  }
}
```

### provision (Background Command)

Runs in background via `at now` to avoid blocking SSH connections. Broadcasts status updates via Reverb WebSocket.

**Status Flow:**
1. `provisioning` - Initial state
2. `creating_repo` - Creating GitHub repository from template
3. `cloning` - Cloning repository
4. `setting_up` - Running composer install, npm install, env setup
5. `finalizing` - Registering with orchestrator
6. `ready` - Complete (broadcast BEFORE Caddy reload to avoid WebSocket disconnect)

### ReverbBroadcaster Service

Broadcasts provisioning events via Pusher SDK to Reverb WebSocket server:

```php
$broadcaster->broadcast('provisioning', 'project.provision.status', [
    'slug' => 'my-app',
    'status' => 'ready',
    'timestamp' => now()->toIso8601String(),
]);
```

**Channels:**
- `provisioning` - Global channel for all events
- `project.{slug}` - Project-specific channel

**Configuration** (~/.config/launchpad/config.json):
```json
{
  "reverb": {
    "app_id": "launchpad",
    "app_key": "launchpad-key",
    "app_secret": "launchpad-secret",
    "host": "reverb.ccc",
    "port": 443,
    "internal_port": 6001
  },
  "services": {
    "reverb": { "enabled": true }
  }
}
```

The broadcaster connects to internal port 6001 (HTTP) to avoid TLS certificate issues when broadcasting from the same server.
