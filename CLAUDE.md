# Orbit CLI

A Laravel Zero CLI tool for managing local PHP development environments using Docker containers.

## Project Overview

Orbit sets up a complete local development environment with:

- **Caddy** - Web server with automatic HTTPS (TLS internal)
- **PHP 8.4 & 8.5** - Multiple PHP versions via PHP-FPM on host
- **PostgreSQL** - Database server
- **Redis** - Cache and session store
- **Mailpit** - Local mail testing
- **DNS** - Local DNS resolver for `.test` domains

## Architecture

```
app/
├── Commands/          # CLI commands (Laravel Zero)
│   ├── InitCommand.php          # Initialize orbit configuration
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
    ├── McpClient.php            # MCP client for sequence
    └── ReverbBroadcaster.php    # WebSocket broadcasting
```

## Commands

All commands support `--json` flag for machine-readable output.

| Command                                       | Description                                     |
| --------------------------------------------- | ----------------------------------------------- |
| `orbit init`                              | Initialize configuration                        |
| `orbit start`                             | Start all services                              |
| `orbit stop`                              | Stop all services                               |
| `orbit restart`                           | Restart all services                            |
| `orbit status`                            | Show service status                             |
| `orbit sites`                             | List all sites                                  |
| `orbit php <site> <version>`              | Set PHP version for a site                      |
| `orbit php <site> --reset`                | Reset to default PHP version                    |
| `orbit logs [service]`                    | View service logs                               |
| `orbit trust`                             | Trust the local CA certificate                  |
| `orbit upgrade`                           | Upgrade to the latest version                   |
| `orbit upgrade --check`                   | Check for available updates                     |
| `orbit worktrees [site]`                  | List git worktrees with subdomains              |
| `orbit worktree:refresh`                  | Refresh and auto-link new worktrees             |
| `orbit worktree:unlink <site> <worktree>` | Unlink worktree from site                       |
| `orbit project:list`                      | List all directories in scan paths              |
| `orbit project:scan`                      | Scan for git repos in configured paths          |
| `orbit project:update [path]`             | Update project (git pull + deps)                |
| `orbit project:delete <slug>`             | Delete project with cascade                     |
| `orbit provision:status <slug>`           | Check provisioning status                       |
| `orbit config:migrate`                    | Migrate config.json to SQLite                   |
| `orbit reverb:setup`                      | Setup Reverb WebSocket service                  |
| `orbit migrate:to-fpm`                    | Migrate from FrankenPHP to PHP-FPM architecture |
| `orbit horizon:status`                    | Check Horizon service status                    |
| `orbit horizon:start`                     | Start Horizon service                           |
| `orbit horizon:stop`                      | Stop Horizon service                            |
| `orbit horizon:restart`                   | Restart Horizon service                         |

## JSON Output Format

All commands support `--json` for structured output:

```json
{"success": true, "data": {...}}   // Success
{"success": false, "error": "..."}  // Error
```

Test with: `orbit status --json | jq .` or `orbit sites --json | jq '.data.sites'`

## Git Worktree Support

Orbit automatically detects git worktrees and creates subdomains for them:

- Worktrees are accessible via `<worktree>.<site>.test` (e.g., `feature-auth.myapp.test`)
- Run `worktree:refresh` after creating new worktrees to update Caddy routing
- Worktrees inherit the parent site's PHP version

## Exit Codes

Defined in `App\Enums\ExitCode`:

| Code | Meaning                 |
| ---- | ----------------------- |
| 0    | Success                 |
| 1    | General error           |
| 2    | Invalid arguments       |
| 3    | Docker not running      |
| 4    | Service failed to start |
| 5    | Configuration error     |

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

User config is stored at `~/.config/orbit/config.json`:

```json
{
    "paths": ["/home/user/projects"],
    "tld": "test",
    "default_php_version": "8.3"
}
```

## Data Storage

### SQLite Database

PHP version overrides and project metadata are stored in SQLite at `~/.config/orbit/database.sqlite`:

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

The CLI communicates with sequence via `McpClient` for project management operations. Configure the sequence URL in config.json:

```json
{
    "sequence": {
        "url": "http://localhost:8000"
    }
}
```

The MCP client handles `.ccc` TLD resolution by mapping to localhost, ensuring background processes work without DNS access.

## Docker Containers (Services)

The following services run in Docker containers:

| Container            | Purpose                           |
| -------------------- | --------------------------------- |
| `orbit-dns`      | Local DNS resolver (dnsmasq)      |
| `orbit-postgres` | PostgreSQL database               |
| `orbit-redis`    | Redis cache                       |
| `orbit-mailpit`  | Mail catcher                      |
| `orbit-reverb`   | WebSocket server (Laravel Reverb) |

**Services running on host (not containerized):**

- **PHP-FPM**: Multiple pools at `~/.config/orbit/php/php{version}.sock`
- **Caddy**: Web server with automatic HTTPS
- **Horizon**: Queue worker as systemd (Linux) or launchd (macOS) service

## PHP-FPM Architecture

PHP-FPM runs directly on the host OS with Caddy as the web server. This replaces the previous FrankenPHP container-based architecture.

### Architecture Overview

- PHP-FPM runs directly on the host OS (not containerized)
- Caddy runs as a binary on the host
- Horizon runs as a systemd/launchd service
- Better performance and resource usage
- Easier debugging and log access

#### Services on Host

- **PHP-FPM**: Runs as systemd (Linux) or Homebrew (macOS) service with custom pools per version
- **Caddy**: Single Caddy binary on host (not containerized)
- **Horizon**: Runs as systemd/launchd service

#### Key Files

- `~/.config/orbit/php/php{version}.sock` - FPM sockets
- `~/.config/orbit/php/php{version}-fpm.conf` - Pool configs
- `/etc/systemd/system/orbit-horizon.service` - Horizon service (Linux)
- `~/Library/LaunchAgents/com.orbit.horizon.plist` - Horizon service (macOS)

#### Platform Adapters

- `LinuxAdapter` - Uses apt, Ondřej PPA, systemd
- `MacAdapter` - Uses Homebrew, launchd

#### Key Services

- `PhpManager` - PHP-FPM version management
- `CaddyManager` - Host Caddy lifecycle
- `HorizonManager` - Horizon as system service

## Prerequisites

### Required

| Dependency | macOS                                    | Linux            |
| ---------- | ---------------------------------------- | ---------------- |
| PHP >= 8.2 | Homebrew (shivammathur/php)              | apt (Ondřej PPA) |
| Docker     | OrbStack (recommended) or Docker Desktop | docker.io        |
| Composer   | Homebrew                                 | apt              |

### Optional

| Dependency | Purpose       | macOS    | Linux                  |
| ---------- | ------------- | -------- | ---------------------- |
| dig        | DNS debugging | Built-in | `apt install dnsutils` |

The `orbit init` command will check for and offer to install missing prerequisites automatically.

### Container Runtime (macOS)

**OrbStack is recommended** over Docker Desktop for macOS:

| Metric    | OrbStack     | Docker Desktop |
| --------- | ------------ | -------------- |
| Startup   | 2 seconds    | 20-30 seconds  |
| RAM usage | ~1GB         | ~6GB           |
| File I/O  | 2-10x faster | Baseline       |

OrbStack is a drop-in replacement - all `docker` commands work identically.

```bash
# Install OrbStack
brew install orbstack

# Migrate from Docker Desktop (optional)
orb migrate docker
```

## DNS Configuration

Orbit uses a Docker container (`orbit-dns`) running dnsmasq to resolve custom TLD domains.

**How it works:** System DNS → 127.0.0.1 → Docker DNS → resolves `.{tld}` to `HOST_IP`

### Setup

| Platform  | Setup                                                                           |
| --------- | ------------------------------------------------------------------------------- |
| **macOS** | `sudo networksetup -setdnsservers Wi-Fi 127.0.0.1`                              |
| **Linux** | Disable `systemd-resolved` stub listener, point `/etc/resolv.conf` to 127.0.0.1 |

The `orbit init` command guides through DNS setup and detects existing dnsmasq installations.

### Troubleshooting

```bash
# Test DNS resolution
dig myproject.test @127.0.0.1

# Check DNS container
docker ps | grep orbit-dns
docker logs orbit-dns

# Port 53 in use? Check what's using it:
sudo lsof -i :53
```

### Config

| Setting   | Default     | Description                   |
| --------- | ----------- | ----------------------------- |
| `tld`     | `test`      | Top-level domain for sites    |
| `host_ip` | `127.0.0.1` | IP address for TLD resolution |

## Development

### Running the CLI

```bash
# From project root
php orbit <command>

# Or with executable
./orbit <command>
```

### Quality Tools

| Tool               | Command                        | Description                           |
| ------------------ | ------------------------------ | ------------------------------------- |
| PHPStan (Larastan) | `./vendor/bin/phpstan analyse` | Static analysis at level 5            |
| Rector             | `./vendor/bin/rector`          | Automated refactoring (PHP 8.2 rules) |
| Pint               | `./vendor/bin/pint`            | Laravel code style formatting         |
| Pest               | `./vendor/bin/pest`            | Test suite (41 tests, 128 assertions) |

### Running All Checks

```bash
./vendor/bin/rector --dry-run
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pest
```

### Test Coverage

Tests are located in `tests/` directory:

| Category | Tests                                                                                                           |
| -------- | --------------------------------------------------------------------------------------------------------------- |
| Commands | StatusCommand, SitesCommand, PhpCommand, StartCommand, StopCommand, RestartCommand, LogsCommand, UpgradeCommand |
| Services | SiteScanner, ConfigManager, CaddyfileGenerator, PhpComposeGenerator                                             |
| Enums    | ExitCode                                                                                                        |

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
php orbit status --json | jq .
php orbit sites --json | jq '.data.sites'
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

This CLI is designed to be controlled by the **orbit-desktop** NativePHP application. The desktop app communicates via:

- **Local execution:** `Process::run('orbit status --json')`
- **Remote execution:** `ssh user@host "cd ~/project./orbit && php orbit status --json"`

The `--json` flag ensures machine-readable output for programmatic control.

## MCP Server

Orbit includes a Model Context Protocol (MCP) server that enables AI tools to understand infrastructure and configure Laravel projects correctly (preventing redundant Redis/PostgreSQL/Mailpit installations).

**Transport:** stdio via `orbit mcp:start orbit` (TLD-independent, works when containers are down)

**Client Configuration** (`~/.mcp.json`):

```json
{
    "mcpServers": {
        "orbit": {
            "command": "orbit",
            "args": ["mcp:start", "orbit"]
        }
    }
}
```

**File Structure:** `app/Mcp/{Servers,Tools,Resources,Prompts}/`

### Tools

| Tool                       | Description                            | Parameters                         |
| -------------------------- | -------------------------------------- | ---------------------------------- |
| `orbit_status`         | Get service status, health, site count | None                               |
| `orbit_start`          | Start all Docker services              | None                               |
| `orbit_stop`           | Stop all Docker services               | None                               |
| `orbit_restart`        | Restart all Docker services            | None                               |
| `orbit_sites`          | List all registered sites              | None                               |
| `orbit_php`            | Get/set PHP version for a site         | `site`, `action`, `version?`       |
| `orbit_project_create` | Create a new project                   | `name`, `template?`, `visibility?` |
| `orbit_project_delete` | Delete a project                       | `slug`                             |
| `orbit_logs`           | Get service logs                       | `service`, `lines?`                |
| `orbit_worktrees`      | List git worktrees                     | `site?`                            |

### Resources

| URI                               | Description                                                |
| --------------------------------- | ---------------------------------------------------------- |
| `orbit://infrastructure`      | All Docker services with status, health, ports             |
| `orbit://config`              | TLD, default PHP version, paths, enabled services          |
| `orbit://env-template/{type}` | .env templates (database, redis, mail, broadcasting, full) |
| `orbit://sites`               | All sites with domains, PHP versions, paths                |

### Prompts

| Prompt                  | Description                                             |
| ----------------------- | ------------------------------------------------------- |
| `configure-laravel-env` | Guide for configuring .env for Orbit infrastructure |
| `setup-horizon`         | Guide for setting up Laravel Horizon with Orbit     |

### Testing

```bash
orbit mcp:inspector orbit     # Interactive testing UI
./vendor/bin/pest tests/Feature/Mcp   # Run MCP tests (47 tests)
```

## Project Provisioning

### Commands

| Command          | Description                                  |
| ---------------- | -------------------------------------------- |
| `project:create` | Create a new project with async provisioning |
| `provision`      | Background command that provisions a project |

### project:create

Creates a new project placeholder and starts background provisioning:

```bash
orbit project:create my-app \
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
5. `finalizing` - Registering with sequence
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

**Configuration** (~/.config/orbit/config.json):

```json
{
    "reverb": {
        "app_id": "orbit",
        "app_key": "orbit-key",
        "app_secret": "orbit-secret",
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

| Status                | Description                                 |
| --------------------- | ------------------------------------------- |
| `provisioning`        | Initial state                               |
| `creating_repo`       | Creating GitHub repository from template    |
| `cloning`             | Cloning repository to ~/projects/{slug}     |
| `setting_up`          | Early Caddy reload + initial env setup      |
| `installing_composer` | Running composer install                    |
| `installing_npm`      | Running bun install                         |
| `building`            | Running bun run build                       |
| `finalizing`          | Database migrations + sequence registration |
| `ready`               | Complete - project ready to use             |
| `failed`              | Error occurred (includes error message)     |

### Timing Benchmarks (liftoff-starterkit)

| Step                      | Time        |
| ------------------------- | ----------- |
| GitHub repo + propagation | ~7s         |
| Git clone                 | ~2s         |
| Caddy early reload        | ~1s         |
| Composer install          | ~4s         |
| Bun install               | ~1s         |
| Bun build                 | ~7s         |
| Env/Key/Migrate           | ~1s         |
| Sequence registration     | ~2s         |
| **Total**                 | **~22-25s** |

## Testing Provisioning

Use the test script on the remote server:

```bash
# Quick test with random project name
ssh orbit@10.8.0.16 "bash -s" < .claude/scripts/test-provision-flow.sh

# Custom project name
ssh orbit@10.8.0.16 "bash -s" < .claude/scripts/test-provision-flow.sh my-test-project
```

See the full testing guide in the orbit-desktop repo: `.claude/skills/test-provision/SKILL.md`

## Troubleshooting Provisioning

| Problem                 | Cause                          | Solution                                                    |
| ----------------------- | ------------------------------ | ----------------------------------------------------------- |
| Bun install hangs       | Non-TTY progress output blocks | CLI uses CI=1 and --no-progress flags (v0.0.17+)            |
| Bun timeout (180s)      | Peer dependency conflicts      | CLI auto-falls back to `npm install --legacy-peer-deps`     |
| Provisioning > 30s      | Check bun hang, network, deps  | `timeout 30 orbit provision test-project ...`           |
| SQLite instead of pgsql | Provisioning failed early      | `sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=pgsql/' .env` |

**SSH Non-TTY:** Always use `--no-progress` for bun in non-interactive contexts (SSH, Horizon jobs).

## Orbit Web App & Queue Processing

Web app (`~/.config/orbit/web/`) provides API for project management. Horizon (systemd/launchd service) processes queue jobs.

**Key Concept:** Web app (via PHP-FPM) and Horizon both run on the host. They connect to Docker services via localhost (ports exposed to host).

**Important .env settings:** Since Horizon runs on host (not in Docker), use `localhost` for service hosts:

- `REDIS_HOST=localhost` (NOT `orbit-redis`)
- `REVERB_HOST=localhost` (NOT `orbit-reverb`)

### API Endpoints

| Endpoint        | Method | Description                                  |
| --------------- | ------ | -------------------------------------------- |
| `/api/projects` | POST   | Create project (dispatches CreateProjectJob) |
| `/api/projects` | GET    | List all projects                            |
| `/api/status`   | GET    | Get orbit status                         |

### Horizon Commands

```bash
orbit horizon:status                              # Check status
orbit horizon:start                               # Start Horizon service
orbit horizon:stop                                # Stop Horizon service
orbit horizon:restart                             # Restart Horizon service
journalctl -u orbit-horizon -f                    # View logs (Linux)
tail -f ~/.config/orbit/web/storage/logs/horizon.log  # View app logs
```

### Troubleshooting Queue

```bash
systemctl status orbit-horizon                    # Check Horizon running (Linux)
launchctl list | grep horizon                         # Check Horizon running (macOS)
redis-cli -h 127.0.0.1 LLEN orbit_horizon:default # Check pending jobs
tail -f ~/.config/orbit/web/storage/logs/laravel.log  # View logs
```

## PHP-FPM Permissions

PHP-FPM runs as the `orbit` user on the host, with direct access to all project files.

```bash
# Verify PHP-FPM is running
systemctl status php8.4-fpm  # Linux
brew services list | grep php  # macOS

# Check FPM pool status
cat ~/.config/orbit/php/php84-fpm.conf
```

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

| Component          | Purpose                                                               |
| ------------------ | --------------------------------------------------------------------- |
| `ProvisionContext` | Immutable DTO with slug, projectPath, drivers, options                |
| `StepResult`       | Action result: `StepResult::success()` or `StepResult::failed('msg')` |
| `ProvisionLogger`  | Logs to file, command output, and WebSocket                           |

**Action signature:**

```php
final readonly class MyAction {
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
}
```

Log files: `~/.config/orbit/logs/provision/{slug}.log`

### Available Actions

| Action                        | Purpose                       | Broadcasts            |
| ----------------------------- | ----------------------------- | --------------------- |
| `CreateGitHubRepository`      | Create repo from template     | `creating_repo`       |
| `ForkRepository`              | Fork an existing repository   | `forking`             |
| `CloneRepository`             | Clone git repository          | `cloning`             |
| `InstallComposerDependencies` | Run composer install          | `installing_composer` |
| `InstallNodeDependencies`     | Run npm/bun/yarn/pnpm install | `installing_npm`      |
| `BuildAssets`                 | Run npm/bun build             | `building`            |
| `ConfigureEnvironment`        | Set up .env file with drivers | -                     |
| `CreateDatabase`              | Create PostgreSQL database    | -                     |
| `GenerateAppKey`              | Run php artisan key:generate  | -                     |
| `RunMigrations`               | Run php artisan migrate       | -                     |
| `RunPostInstallScripts`       | Run composer scripts          | -                     |
| `ConfigureTrustedProxies`     | Configure Laravel 11+ proxies | -                     |
| `SetPhpVersion`               | Detect and set PHP version    | -                     |
| `RestartPhpContainer`         | Restart PHP-FPM container     | -                     |

### Using Actions

```php
$result = app(ConfigureEnvironment::class)->handle($context, $logger);
if ($result->isFailed()) { return $this->error($result->error); }
```

## Service Templating System

Orbit uses a declarative service templating system to manage Docker services. Services are defined using JSON templates and configured via YAML.

### Architecture Overview

```
stubs/templates/           # Service template definitions (JSON)
├── postgres.json
├── redis.json
├── mailpit.json
├── meilisearch.json
├── mysql.json
├── dns.json
└── reverb.json

~/.config/orbit/
├── services.yaml          # User service configuration
└── docker-compose.yaml    # Generated from services.yaml
```

### Key Components

| Component                | File                                      | Purpose                              |
| ------------------------ | ----------------------------------------- | ------------------------------------ |
| `ServiceTemplate`        | `app/Data/ServiceTemplate.php`            | Immutable DTO for template data      |
| `ServiceTemplateLoader`  | `app/Services/ServiceTemplateLoader.php`  | Loads/caches JSON templates          |
| `ServiceConfigValidator` | `app/Services/ServiceConfigValidator.php` | Validates config against schema      |
| `ServiceManager`         | `app/Services/ServiceManager.php`         | Enables/disables/configures services |
| `ComposeGenerator`       | `app/Services/ComposeGenerator.php`       | Generates docker-compose.yaml        |

### Service Template Format

Each service is defined in `stubs/templates/{name}.json`:

```json
{
    "name": "postgres",
    "label": "PostgreSQL",
    "description": "Advanced open source relational database",
    "category": "database",
    "versions": ["17", "16", "15", "14", "13"],
    "required": false,
    "dependsOn": [],
    "configSchema": {
        "required": ["port"],
        "properties": {
            "port": {
                "type": "number",
                "default": 5432,
                "minimum": 1024,
                "maximum": 65535
            },
            "environment": {
                "type": "object",
                "properties": {
                    "POSTGRES_USER": {
                        "type": "string",
                        "default": "orbit"
                    },
                    "POSTGRES_PASSWORD": {
                        "type": "string",
                        "default": "secret"
                    }
                }
            }
        }
    },
    "dockerConfig": {
        "image": "postgres:${version}",
        "ports": ["5432:5432"],
        "volumes": ["${data_path}:/var/lib/postgresql/data"],
        "environment": {
            "POSTGRES_USER": "orbit",
            "POSTGRES_PASSWORD": "secret"
        },
        "healthcheck": {
            "test": ["CMD-SHELL", "pg_isready -U orbit"],
            "interval": "10s",
            "timeout": "5s",
            "retries": 5
        }
    }
}
```

### Template Fields

| Field          | Type     | Description                                         |
| -------------- | -------- | --------------------------------------------------- |
| `name`         | string   | Internal identifier (filename without .json)        |
| `label`        | string   | Human-readable display name                         |
| `description`  | string   | Service description                                 |
| `category`     | string   | Category for grouping (database, cache, mail, etc.) |
| `versions`     | string[] | Available versions (first is default)               |
| `required`     | boolean  | If true, service cannot be disabled                 |
| `dependsOn`    | string[] | Services that must be enabled first                 |
| `configSchema` | object   | JSON Schema for configuration validation            |
| `dockerConfig` | object   | Docker container configuration                      |

### Configuration Schema

The `configSchema` uses JSON Schema format with these supported validators:

| Validator   | Type     | Description                                                 |
| ----------- | -------- | ----------------------------------------------------------- |
| `type`      | string   | Data type: `number`, `string`, `boolean`, `object`, `array` |
| `default`   | mixed    | Default value if not provided                               |
| `required`  | string[] | Fields that must be present                                 |
| `minimum`   | number   | Minimum numeric value                                       |
| `maximum`   | number   | Maximum numeric value                                       |
| `minLength` | number   | Minimum string length                                       |
| `maxLength` | number   | Maximum string length                                       |
| `pattern`   | string   | Regex pattern for strings                                   |
| `enum`      | array    | Allowed values                                              |

### Docker Config Variables

The `dockerConfig` section supports variable interpolation:

| Variable       | Description                                                         |
| -------------- | ------------------------------------------------------------------- |
| `${version}`   | Selected service version from `services.yaml`                       |
| `${port}`      | Configured port from `services.yaml`                                |
| `${data_path}` | Data directory path (default: `~/.config/orbit/data/{service}`) |

### User Configuration (services.yaml)

Users configure services in `~/.config/orbit/services.yaml`:

```yaml
services:
    postgres:
        enabled: true
        version: "17"
        port: 5432
        environment:
            POSTGRES_USER: orbit
            POSTGRES_PASSWORD: secret
            POSTGRES_DB: orbit

    redis:
        enabled: true
        version: "7.4"
        port: 6379
        maxmemory: 256mb
        persistence: true

    mailpit:
        enabled: true
        version: latest
        smtp_port: 1025
        http_port: 8025

    reverb:
        enabled: false
```

### Service Commands

| Command                                              | Description                   |
| ---------------------------------------------------- | ----------------------------- |
| `orbit service:list`                             | List all services with status |
| `orbit service:list --available`                 | List available templates      |
| `orbit service:enable <name>`                    | Enable a service              |
| `orbit service:disable <name>`                   | Disable a service             |
| `orbit service:configure <name> --set key=value` | Configure a service           |
| `orbit service:info <name>`                      | Show service details          |

### Examples

```bash
# Enable MySQL (creates entry in services.yaml with defaults)
orbit service:enable mysql

# Change PostgreSQL version
orbit service:configure postgres --set version=16

# Change Redis port
orbit service:configure redis --set port=6380

# Disable Mailpit
orbit service:disable mailpit

# View service info
orbit service:info postgres --json
```

### Adding a New Service Template

1. Create `stubs/templates/{name}.json` with required fields
2. Define `configSchema` for validation
3. Define `dockerConfig` for container setup
4. Test with `orbit service:enable {name}`

### Service Categories

| Category    | Services        |
| ----------- | --------------- |
| `database`  | postgres, mysql |
| `cache`     | redis           |
| `mail`      | mailpit         |
| `search`    | meilisearch     |
| `websocket` | reverb          |
| `core`      | dns             |

## Testing

Tests use Pest PHP. Helper functions in `tests/Pest.php`:

```bash
./vendor/bin/pest                    # All tests
./vendor/bin/pest tests/Unit         # Unit tests only
./vendor/bin/pest tests/Feature/Mcp  # MCP tests
```

### Test Files (Unit)

| Test File                         | Coverage            |
| --------------------------------- | ------------------- |
| `ConfigureEnvironmentTest.php`    | .env setup, drivers |
| `ConfigureTrustedProxiesTest.php` | Laravel 11+ proxies |
| `DatabaseServiceTest.php`         | PHP version storage |
| `ProvisionContextTest.php`        | Context DTO         |
| `StepResultTest.php`              | Result DTO          |

## E2E Testing

### Desktop Flow Test

The web app includes an E2E test that replicates the desktop app workflow:

```bash
# Run from the web app directory
cd ~/projects/orbit-cli/web
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
- Deletion: `deleting -> removing_sequence -> removing_files -> deleted`

**Requirements:**

- Full orbit stack running (Caddy, PHP, Redis, Horizon, Reverb)
- Web app deployed at `~/.config/orbit/web/`
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

Located at `web/` directory, deployed to `~/.config/orbit/web/`.

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
cp -r ~/projects/orbit-cli/web/* ~/.config/orbit/web/

# Clear config cache and restart Horizon
cd ~/.config/orbit/web && php artisan config:clear
orbit horizon:restart
```
