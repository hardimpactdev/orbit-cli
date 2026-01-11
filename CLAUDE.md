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
    ├── PlatformService.php      # OS/runtime detection, prerequisites
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

All commands support `--json` for structured output:

```json
{"success": true, "data": {...}}   // Success
{"success": false, "error": "..."}  // Error
```

Test with: `launchpad status --json | jq .` or `launchpad sites --json | jq '.data.sites'`

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
| `launchpad-dns` | Local DNS resolver (dnsmasq) |
| `launchpad-php-83` | PHP 8.3 FPM |
| `launchpad-php-84` | PHP 8.4 FPM |
| `launchpad-caddy` | Web server |
| `launchpad-postgres` | PostgreSQL database |
| `launchpad-redis` | Redis cache |
| `launchpad-mailpit` | Mail catcher |
| `launchpad-reverb` | WebSocket server (Laravel Reverb) |
| `launchpad-horizon` | Queue worker (Laravel Horizon) |

## Prerequisites

### Required

| Dependency | macOS | Linux |
|------------|-------|-------|
| PHP >= 8.2 | `php.new` or Homebrew | `php.new` or apt |
| Docker | OrbStack (recommended) or Docker Desktop | docker.io |
| Composer | Homebrew | apt |


### Optional

| Dependency | Purpose | macOS | Linux |
|------------|---------|-------|-------|
| dig | DNS debugging | Built-in | `apt install dnsutils` |

The `launchpad init` command will check for and offer to install missing prerequisites automatically.

### Container Runtime (macOS)

**OrbStack is recommended** over Docker Desktop for macOS:

| Metric | OrbStack | Docker Desktop |
|--------|----------|----------------|
| Startup | 2 seconds | 20-30 seconds |
| RAM usage | ~1GB | ~6GB |
| File I/O | 2-10x faster | Baseline |

OrbStack is a drop-in replacement - all `docker` commands work identically.

```bash
# Install OrbStack
brew install orbstack

# Migrate from Docker Desktop (optional)
orb migrate docker
```

## DNS Configuration

Launchpad uses a Docker container (`launchpad-dns`) running dnsmasq to resolve custom TLD domains.

**How it works:** System DNS → 127.0.0.1 → Docker DNS → resolves `.{tld}` to `HOST_IP`

### Setup

| Platform | Setup |
|----------|-------|
| **macOS** | `sudo networksetup -setdnsservers Wi-Fi 127.0.0.1` |
| **Linux** | Disable `systemd-resolved` stub listener, point `/etc/resolv.conf` to 127.0.0.1 |

The `launchpad init` command guides through DNS setup and detects existing dnsmasq installations.

### Troubleshooting

```bash
# Test DNS resolution
dig myproject.test @127.0.0.1

# Check DNS container
docker ps | grep launchpad-dns
docker logs launchpad-dns

# Port 53 in use? Check what's using it:
sudo lsof -i :53
```

### Config

| Setting | Default | Description |
|---------|---------|-------------|
| `tld` | `test` | Top-level domain for sites |
| `host_ip` | `127.0.0.1` | IP address for TLD resolution |

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


## MCP Server

Launchpad includes a Model Context Protocol (MCP) server that enables AI tools to understand infrastructure and configure Laravel projects correctly (preventing redundant Redis/PostgreSQL/Mailpit installations).

**Transport:** stdio via `launchpad mcp:start launchpad` (TLD-independent, works when containers are down)

**Client Configuration** (`~/.mcp.json`):
```json
{"mcpServers": {"launchpad": {"command": "launchpad", "args": ["mcp:start", "launchpad"]}}}
```

**File Structure:** `app/Mcp/{Servers,Tools,Resources,Prompts}/`

### Tools

| Tool | Description | Parameters |
|------|-------------|------------|
| `launchpad_status` | Get service status, health, site count | None |
| `launchpad_start` | Start all Docker services | None |
| `launchpad_stop` | Stop all Docker services | None |
| `launchpad_restart` | Restart all Docker services | None |
| `launchpad_sites` | List all registered sites | None |
| `launchpad_php` | Get/set PHP version for a site | `site`, `action`, `version?` |
| `launchpad_project_create` | Create a new project | `name`, `template?`, `visibility?` |
| `launchpad_project_delete` | Delete a project | `slug` |
| `launchpad_logs` | Get service logs | `service`, `lines?` |
| `launchpad_worktrees` | List git worktrees | `site?` |

### Resources

| URI | Description |
|-----|-------------|
| `launchpad://infrastructure` | All Docker services with status, health, ports |
| `launchpad://config` | TLD, default PHP version, paths, enabled services |
| `launchpad://env-template/{type}` | .env templates (database, redis, mail, broadcasting, full) |
| `launchpad://sites` | All sites with domains, PHP versions, paths |

### Prompts

| Prompt | Description |
|--------|-------------|
| `configure-laravel-env` | Guide for configuring .env for Launchpad infrastructure |
| `setup-horizon` | Guide for setting up Laravel Horizon with Launchpad |

### Testing

```bash
launchpad mcp:inspector launchpad     # Interactive testing UI
./vendor/bin/pest tests/Feature/Mcp   # Run MCP tests (47 tests)
```


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

When called via API (CreateProjectJob), runs in Horizon queue on the HOST. When called directly via CLI, runs synchronously. Broadcasts status updates via Reverb WebSocket.

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

## Provisioning Optimizations

### Early Caddy Reload

The `provision` command reloads Caddy **immediately after cloning** (before composer/npm install):

```php
// Early Caddy reload: Makes URL accessible immediately and starts SSL cert generation
// This happens in parallel with the rest of setup
$this->info("Early Caddy reload (making {$this->slug}.ccc accessible)...");
$caddyfileGenerator->generate();
$caddyfileGenerator->reload();
$caddyfileGenerator->reloadPhp();
```

**Benefits:**
- URL becomes accessible immediately (returns 503 until setup completes)
- SSL certificate generation starts during composer/npm install
- ~2-3 second improvement in perceived provisioning time

### Granular Status Broadcasts

The provisioning broadcasts granular status updates:

| Status | Description |
|--------|-------------|
| `provisioning` | Initial state |
| `creating_repo` | Creating GitHub repository from template |
| `cloning` | Cloning repository to ~/projects/{slug} |
| `setting_up` | Early Caddy reload + initial env setup |
| `installing_composer` | Running composer install |
| `installing_npm` | Running bun install |
| `building` | Running bun run build |
| `finalizing` | Database migrations + orchestrator registration |
| `ready` | Complete - project ready to use |
| `failed` | Error occurred (includes error message) |

### Timing Benchmarks (liftoff-starterkit)

| Step | Time |
|------|------|
| GitHub repo + propagation | ~7s |
| Git clone | ~2s |
| Caddy early reload | ~1s |
| Composer install | ~4s |
| Bun install | ~1s |
| Bun build | ~7s |
| Env/Key/Migrate | ~1s |
| Orchestrator registration | ~2s |
| **Total** | **~22-25s** |

## Testing Provisioning

Use the test script on the remote server:

```bash
# Quick test with random project name
ssh launchpad@10.8.0.16 "bash -s" < .claude/scripts/test-provision-flow.sh

# Custom project name
ssh launchpad@10.8.0.16 "bash -s" < .claude/scripts/test-provision-flow.sh my-test-project
```

See the full testing guide in the launchpad-desktop repo: `.claude/skills/test-provision/SKILL.md`

## Troubleshooting Provisioning

| Problem | Cause | Solution |
|---------|-------|----------|
| Bun install hangs | Progress bar blocks non-TTY | Use `bun install --no-progress` (built into CLI) |
| Bun timeout (180s) | Peer dependency conflicts | CLI auto-falls back to `npm install --legacy-peer-deps` |
| Provisioning > 30s | Check bun hang, network, deps | `timeout 30 launchpad provision test-project ...` |
| SQLite instead of pgsql | Provisioning failed early | `sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=pgsql/' .env` |

**SSH Non-TTY:** Always use `--no-progress` for bun in non-interactive contexts (SSH, Horizon jobs).


## Launchpad Web App & Queue Processing

Web app (`~/.config/launchpad/web/`) provides API for project management. Horizon (`launchpad-horizon` container) processes queue jobs.

**Key Concept:** Web app (Docker) uses `REDIS_HOST=launchpad-redis`; Horizon (Docker) connects via Docker network.

### API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/projects` | POST | Create project (dispatches CreateProjectJob) |
| `/api/projects` | GET | List all projects |
| `/api/status` | GET | Get launchpad status |

### Horizon Commands

```bash
launchpad horizon:status                              # Check status
docker logs launchpad-horizon --tail 100 -f           # View logs
docker exec launchpad-horizon php artisan config:clear && docker restart launchpad-horizon  # After .env changes
```

### Troubleshooting Queue

```bash
docker ps | grep launchpad-horizon                    # Check Horizon running
redis-cli -h 127.0.0.1 LLEN launchpad_horizon:default # Check pending jobs
tail -f ~/.config/launchpad/web/storage/logs/laravel.log  # View logs
```


## PHP Container Permissions (Non-Root)

PHP containers run as `launchpad` user (UID/GID 1001) to match host file ownership.

| Setting | Value | Purpose |
|---------|-------|---------|
| UID/GID | 1001 | Matches host `launchpad` user |
| Docker GID | 988 | Docker socket access for status checks |

```bash
# Verify container user
docker exec launchpad-php-85 id
# uid=1001(launchpad) gid=1001(launchpad) groups=988(dockerhost)
```

If upgrading from root containers: `cd ~/.config/launchpad/php && docker compose build --no-cache`

## Actions Pattern

The provisioning system uses an **Action Pattern** for clean, testable, single-responsibility classes. Each action handles one step of the provisioning process.

### Directory Structure

```
app/
├── Actions/
│   └── Provision/           # Provisioning action classes
│       ├── BuildAssets.php
│       ├── CloneRepository.php
│       ├── ConfigureEnvironment.php
│       ├── ConfigureTrustedProxies.php
│       ├── CreateDatabase.php
│       ├── CreateGitHubRepository.php
│       ├── ForkRepository.php
│       ├── GenerateAppKey.php
│       ├── InstallComposerDependencies.php
│       ├── InstallNodeDependencies.php
│       ├── RestartPhpContainer.php
│       ├── RunMigrations.php
│       ├── RunPostInstallScripts.php
│       └── SetPhpVersion.php
├── Data/
│   └── Provision/           # DTOs for provisioning
│       ├── ProvisionContext.php   # Context passed through actions
│       └── StepResult.php         # Action result (success/failure)
└── Services/
    └── ProvisionLogger.php  # Unified logging service
```

### Core Components

| Component | Purpose |
|-----------|---------|
| `ProvisionContext` | Immutable DTO with slug, projectPath, drivers, options |
| `StepResult` | Action result: `StepResult::success()` or `StepResult::failed('msg')` |
| `ProvisionLogger` | Logs to file, command output, and WebSocket |

**Action signature:**
```php
final readonly class MyAction {
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
}
```

Log files: `~/.config/launchpad/logs/provision/{slug}.log`

### Available Actions

| Action | Purpose | Broadcasts |
|--------|---------|------------|
| `CreateGitHubRepository` | Create repo from template | `creating_repo` |
| `ForkRepository` | Fork an existing repository | `forking` |
| `CloneRepository` | Clone git repository | `cloning` |
| `InstallComposerDependencies` | Run composer install | `installing_composer` |
| `InstallNodeDependencies` | Run npm/bun/yarn/pnpm install | `installing_npm` |
| `BuildAssets` | Run npm/bun build | `building` |
| `ConfigureEnvironment` | Set up .env file with drivers | - |
| `CreateDatabase` | Create PostgreSQL database | - |
| `GenerateAppKey` | Run php artisan key:generate | - |
| `RunMigrations` | Run php artisan migrate | - |
| `RunPostInstallScripts` | Run composer scripts | - |
| `ConfigureTrustedProxies` | Configure Laravel 11+ proxies | - |
| `SetPhpVersion` | Detect and set PHP version | - |
| `RestartPhpContainer` | Restart PHP-FPM container | - |

### Using Actions

```php
$result = app(ConfigureEnvironment::class)->handle($context, $logger);
if ($result->isFailed()) { return $this->error($result->error); }
```

## Testing

Tests use Pest PHP. Helper functions in `tests/Pest.php`:

```bash
./vendor/bin/pest                    # All tests
./vendor/bin/pest tests/Unit         # Unit tests only
./vendor/bin/pest tests/Feature/Mcp  # MCP tests
```

### Test Files (Unit)

| Test File | Coverage |
|-----------|----------|
| `ConfigureEnvironmentTest.php` | .env setup, drivers |
| `ConfigureTrustedProxiesTest.php` | Laravel 11+ proxies |
| `DatabaseServiceTest.php` | PHP version storage |
| `ProvisionContextTest.php` | Context DTO |
| `StepResultTest.php` | Result DTO |


## E2E Testing

### Desktop Flow Test

The web app includes an E2E test that replicates the desktop app workflow:

```bash
# Run from the web app directory
cd ~/projects/launchpad-cli/web
php tests/e2e-desktop-flow-test.php

# Use different TLD
TLD=test php tests/e2e-desktop-flow-test.php

# Create project but skip deletion (for debugging)
php tests/e2e-desktop-flow-test.php --keep
```

**What it tests:**
1. Creates a project via `POST /api/projects` (uses `hardimpactdev/liftoff-starterkit`)
2. Tracks provisioning status until `ready` or `failed`
3. Deletes the project via `DELETE /api/projects/{slug}`
4. Tracks deletion status until `deleted` or `delete_failed`

**Expected status flows:**
- Provision: `provisioning -> creating_repo -> cloning -> setting_up -> installing_composer -> installing_npm -> building -> finalizing -> ready`
- Deletion: `deleting -> removing_orchestrator -> removing_files -> deleted`

**Requirements:**
- Full launchpad stack running (Caddy, PHP, Redis, Horizon, Reverb)
- Web app deployed at `~/.config/launchpad/web/`
- Horizon processing jobs

## WebSocket Broadcasting

### Architecture

The CLI broadcasts status updates via Reverb (Laravel Reverb):

```
CLI (ReverbBroadcaster) -> Pusher HTTP API -> Reverb container -> WebSocket -> Desktop app
```

The desktop app connects via WebSocket to receive real-time updates.

### Important: Caddy Reload and WebSocket Connections

**Reverb traffic is proxied through Caddy.** When Caddy reloads (e.g., after site config changes), WebSocket connections are briefly dropped.

**Critical ordering for broadcasts:**
When performing operations that trigger a Caddy reload, broadcast the final status BEFORE reloading Caddy:

```php
// CORRECT - broadcast before Caddy reload
$this->logger->broadcast('deleted');
$caddy->generate();
$caddy->reload();  // This drops WebSocket connections

// WRONG - broadcast after Caddy reload (clients won't receive it)
$caddy->generate();
$caddy->reload();
$this->logger->broadcast('deleted');  // Connection already dropped!
```

### Broadcast Channels

Events are broadcast to two channels:
1. `provisioning` - Global channel for all events (desktop subscribes at connect time)
2. `project.{slug}` - Project-specific channel (desktop subscribes when tracking a specific project)

### Event Names

- `project.provision.status` - Provisioning status updates
- `project.deletion.status` - Deletion status updates

Both events include: `{ slug, status, timestamp, error? }`

## Web App (API Backend)

Located at `web/` directory, deployed to `~/.config/launchpad/web/`.

### Job Flow

1. **Desktop** calls API endpoint (e.g., `POST /api/projects`)
2. **API** dispatches job to Redis queue (e.g., `CreateProjectJob`)
3. **Horizon** (runs on HOST) picks up job and executes CLI command
4. **CLI** performs operation and broadcasts status via `ReverbBroadcaster`
5. **Desktop** receives updates via WebSocket

### Updating the Deployed Web App

After making changes to `web/`:

```bash
# Copy to deployed location
cp -r ~/projects/launchpad-cli/web/* ~/.config/launchpad/web/

# Restart Horizon to pick up changes
docker restart launchpad-horizon
```
