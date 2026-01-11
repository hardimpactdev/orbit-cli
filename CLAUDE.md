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

Launchpad uses a Docker container running dnsmasq to resolve custom TLD domains (e.g., `.test`, `.ccc`). The TLD is configurable in `config.json`.

### How It Works

```
┌─────────────────────────────────────────────────────────────┐
│  System DNS → 127.0.0.1 → Docker DNS Container              │
│                              │                              │
│                    ┌─────────┴─────────┐                    │
│                    ↓                   ↓                    │
│              .{tld} → HOST_IP    other → upstream DNS       │
└─────────────────────────────────────────────────────────────┘
```

The DNS container (`launchpad-dns`) runs with `network_mode: host` and executes:
```bash
dnsmasq -k --address=/.{TLD}/{HOST_IP}
```

### Why Docker DNS?

Docker DNS handles **any TLD** without per-TLD configuration. This is important for:
- Multiple environments (local `.test`, remote `.ccc`, etc.)
- Changing TLD without reconfiguring DNS
- Consistent setup across macOS and Linux

Alternative approaches like dnsmasq on the host require manual `/etc/resolver/{tld}` files for each TLD.

### macOS DNS Setup

**Option A: System DNS (recommended for simplicity)**

Point system DNS to 127.0.0.1:
```bash
# Via command line
sudo networksetup -setdnsservers Wi-Fi 127.0.0.1

# Or via System Settings
# System Settings → Network → Wi-Fi → Details → DNS → Add 127.0.0.1
```

**Option B: Existing dnsmasq**

If you already have dnsmasq configured for your TLD, Launchpad can use it. The init command will detect this and skip the Docker DNS container.

Check if you have dnsmasq for your TLD:
```bash
# Check resolver file exists
cat /etc/resolver/test

# Check dnsmasq is running
brew services list | grep dnsmasq
```

### Linux DNS Setup

Linux requires additional configuration because `systemd-resolved` typically binds to port 53.

**Step 1: Disable systemd-resolved's DNS stub listener**

Create `/etc/systemd/resolved.conf.d/launchpad.conf`:
```ini
[Resolve]
DNSStubListener=no
```

Then restart systemd-resolved:
```bash
sudo systemctl restart systemd-resolved
```

**Step 2: Configure /etc/resolv.conf**

```bash
# Point to localhost (where Docker DNS will listen)
sudo sh -c 'echo "nameserver 127.0.0.1" > /etc/resolv.conf'
sudo sh -c 'echo "nameserver 1.1.1.1" >> /etc/resolv.conf'
```

Note: On some systems, `/etc/resolv.conf` is managed by systemd-resolved or NetworkManager. You may need to configure those services instead.

**Step 3: Verify**

```bash
# Check port 53 is free for Docker DNS
sudo ss -tlnp | grep :53

# After starting Launchpad, test resolution
dig myproject.test @127.0.0.1
```

### DNS Troubleshooting

**Port 53 already in use:**
```bash
# Find what's using port 53
sudo lsof -i :53

# Common culprits:
# - systemd-resolved (disable DNSStubListener)
# - existing dnsmasq (can coexist if configured for same TLD)
# - other DNS software
```

**DNS not resolving:**
```bash
# Test Docker DNS directly
dig myproject.test @127.0.0.1

# Check DNS container is running
docker ps | grep launchpad-dns

# Check DNS container logs
docker logs launchpad-dns

# Verify system DNS points to 127.0.0.1
cat /etc/resolv.conf        # Linux
scutil --dns                 # macOS
```

**macOS: DNS works with dig but not browser:**
```bash
# Flush DNS cache
sudo dscacheutil -flushcache
sudo killall -HUP mDNSResponder
```

### Multi-Environment Setup

Launchpad supports multiple environments with different TLDs:

| Environment | TLD | HOST_IP | Use Case |
|-------------|-----|---------|----------|
| Local (Mac) | `.test` | `127.0.0.1` | Local development |
| Remote Server | `.ccc` | `10.8.0.16` | Cloud dev environment |

The Docker DNS container automatically handles any TLD configured in `config.json`. When connecting to a remote environment, the `McpClient` automatically resolves `.ccc` domains to the configured host.

### Config Reference

```json
{
  "tld": "test",
  "host_ip": "127.0.0.1"
}
```

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

### Bun Install Hangs (Non-TTY Environments)

**Problem:** Bun install hangs indefinitely on "Resolving dependencies" when running via SSH or in non-TTY environments.

**Cause:** Bun's progress bar blocks on non-TTY terminals waiting for input that never comes.

**Solution:** Always use `--no-progress` flag when running bun install in non-interactive contexts:

```bash
bun install --no-progress
bun run build --silent
```

The ProvisionCommand has this fix built-in. If you see hangs, verify the flag is present:
```bash
grep 'no-progress' ~/projects/launchpad-cli/app/Commands/ProvisionCommand.php
```

### Bun Install Timeout (Peer Dependency Conflicts)

**Problem:** Bun install times out after 180 seconds with peer dependency conflicts.

**Solution:** The CLI automatically falls back to npm with `--legacy-peer-deps`:

```php
// In ProvisionCommand.php
$npmResult = Process::path($this->projectPath)
    ->timeout(600)
    ->run('npm install --legacy-peer-deps 2>&1');
```

To test npm directly:
```bash
npm install --legacy-peer-deps
```

### Provisioning Takes > 30 Seconds

If provisioning takes longer than 30 seconds with liftoff-starterkit, something is wrong. Common causes:

1. **Bun hanging** - Check if `--no-progress` flag is missing
2. **Network issues** - GitHub/npm registry slow
3. **Large dependencies** - Template has unusually large npm dependencies

Debug by running provision directly with timeout:
```bash
timeout 30 launchpad provision test-project --template=hardimpactdev/liftoff-starterkit --db-driver=pgsql --visibility=private 2>&1
```

### PostgreSQL Not Configured

**Problem:** Project uses SQLite instead of PostgreSQL despite selecting pgsql in settings.

**Cause:** Provisioning failed before `configureEnv()` ran (e.g., bun install hung).

**Solution:** After fixing the provisioning issue, manually configure:
```bash
cd ~/projects/my-project
sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=pgsql/' .env
php artisan migrate:fresh
```

### SSH TTY vs Non-TTY Behavior

Some commands behave differently in TTY vs non-TTY:
- TTY (`ssh -t`): Interactive mode with progress bars
- Non-TTY (regular ssh): Commands may hang waiting for TTY input

Force TTY for debugging:
```bash
ssh -t launchpad@10.8.0.16 "cd ~/projects/test && bun install"
```

Non-TTY safe commands (what the CLI uses):
```bash
ssh launchpad@10.8.0.16 "cd ~/projects/test && bun install --no-progress"
```


### Historical Fixes (Jan 2026)

**PATH Bug in CreateProjectJob:**
- **Issue:** Bun install hung indefinitely during API-based provisioning
- **Cause:** PATH was malformed as `$home/home/launchpad/.bun/bin` (double home directory)
- **Fix:** Changed to proper interpolation `{$home}/.bun/bin`
- **Location:** `~/.config/launchpad/web/app/Jobs/CreateProjectJob.php`

**ProjectController Using `at now`:**
- **Issue:** Projects created via API never provisioned
- **Cause:** `at now` daemon doesn't work from Docker container (no `at` daemon inside container)
- **Fix:** Changed to dispatch `CreateProjectJob` via Horizon (which runs on HOST)
- **Location:** `~/.config/launchpad/web/app/Http/Controllers/Api/ProjectController.php`

**Broadcast Exceptions Failing Jobs:**
- **Issue:** Jobs failed with "Could not resolve host: reverb.ccc"
- **Cause:** Horizon runs on HOST which doesn't use launchpad DNS resolver
- **Fix:** Made `broadcast()` catch exceptions (non-blocking) so jobs complete even if WebSocket unreachable
- **Location:** `~/.config/launchpad/web/app/Jobs/CreateProjectJob.php`

**APP_KEY Environment Variable Inheritance (Laravel Bug):**
- **Issue:** `php artisan key:generate` failed with "No APP_KEY variable was found in the .env file" even though `.env` had `APP_KEY=`
- **Cause:** When provisioning runs via Horizon, the web app's `APP_KEY` env var is inherited by child processes. Laravel's `key:generate` uses the config value (from env) to build a regex pattern looking for `APP_KEY=base64:xyz...` in `.env`, but the new project's `.env` has `APP_KEY=` (empty), so the regex doesn't match.
- **Root Cause:** This is a Laravel framework bug - `KeyGenerateCommand::keyReplacementPattern()` uses `$this->laravel['config']['app.key']` instead of reading the `.env` file directly. PR submitted to laravel/framework.
- **Fix:** Use `env -i` to clear inherited environment before running artisan commands:
  ```php
  $command = "env -i HOME={$home} PATH=... php artisan key:generate --force";
  ```
- **Location:** `app/Actions/Provision/GenerateAppKey.php`
- **Why this affects Horizon but not direct CLI:** When you run `launchpad provision` directly from SSH, your shell doesn't have `APP_KEY` set. But Horizon workers inherit the web app's environment (including `APP_KEY`), which then gets passed to the CLI phar and ultimately to `php artisan`.

## Launchpad Web App & Queue Processing

The CLI includes a Laravel web app (`~/.config/launchpad/web/`) that provides an API for project management and uses Horizon for queue processing.

### Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Desktop App / API Client                                               │
│           │                                                             │
│           ▼                                                             │
│  ┌─────────────────────┐     ┌─────────────────────┐                    │
│  │ Launchpad Web App   │     │  launchpad-redis    │                    │
│  │ (FrankenPHP/Docker) │────▶│  (Docker container) │                    │
│  │ REDIS_HOST=         │     │                     │                    │
│  │ launchpad-redis     │     └──────────┬──────────┘                    │
│  └─────────────────────┘                │                               │
│                                         │ 127.0.0.1:6379                │
│                                         ▼                               │
│                           ┌─────────────────────────┐                   │
│                           │  Horizon (on host)      │                   │
│                           │  REDIS_HOST=127.0.0.1   │                   │
│                           │         │               │                   │
│                           │         ▼               │                   │
│                           │  launchpad provision    │                   │
│                           └─────────────────────────┘                   │
└─────────────────────────────────────────────────────────────────────────┘
```

### Key Concepts

- **Web App (in Docker)**: Accepts API requests, dispatches jobs to Redis queue
- **Redis Container**: Shared queue storage, accessible as `launchpad-redis` from containers
- **Horizon (on host)**: Picks up jobs from Redis, runs CLI commands like `launchpad provision`
- **Different REDIS_HOST**: Containers use `launchpad-redis`, host processes use `127.0.0.1`

### Configuration Files

**Web App .env** (`~/.config/launchpad/web/.env`):
```bash
QUEUE_CONNECTION=redis
REDIS_HOST=launchpad-redis    # Docker network name (for web requests)
LOG_LEVEL=debug
```

**Horizon Config** (`~/.config/launchpad/web/config/horizon.php`):
```php
timeout => 900,  // 15 minutes for long-running provision jobs
```

**CreateProjectJob PATH** (`~/.config/launchpad/web/app/Jobs/CreateProjectJob.php`):
```php
->env([
    HOME => $_SERVER[HOME] ?? /home/launchpad,
    PATH => ($_SERVER[HOME] ?? /home/launchpad) . /.bun/bin: .
              ($_SERVER[HOME] ?? /home/launchpad) . /.local/bin: .
              ($_SERVER[HOME] ?? /home/launchpad) . /.config/herd-lite/bin: .
              /usr/local/bin:/usr/bin:/bin,
])
```

Note: `.bun/bin` must be in PATH for `bun install` to work during provisioning.

### Supervisord Configuration

Horizon is managed by supervisord for persistence and auto-restart:

**Launchpad Horizon** (`/etc/supervisor/conf.d/launchpad-horizon.conf`):
```ini
[program:launchpad-horizon]
process_name=%(program_name)s
command=php /home/launchpad/.config/launchpad/web/artisan horizon
autostart=true
autorestart=true
user=launchpad
environment=HOME="/home/launchpad",REDIS_HOST="127.0.0.1",PATH="/home/launchpad/.config/herd-lite/bin:/home/launchpad/.bun/bin:/home/launchpad/.local/bin:/usr/local/bin:/usr/bin:/bin"
redirect_stderr=true
stdout_logfile=/home/launchpad/.config/launchpad/web/storage/logs/supervisor-horizon.log
stopwaitsecs=10
```

### Multiple Apps Sharing Redis

Multiple Laravel apps can share the same Redis instance for Horizon. Each app uses a unique prefix based on APP_NAME:

| App | APP_NAME | Horizon Prefix |
|-----|----------|----------------|
| Launchpad Web | `Launchpad` | `launchpad_horizon:` |
| Foundry | `Foundry` | `foundry_horizon:` |

**Foundry Horizon** (`/etc/supervisor/conf.d/foundry.conf`):
```ini
[program:foundry-horizon]
process_name=%(program_name)s
command=php /home/launchpad/projects/foundry/artisan horizon
autostart=true
autorestart=true
user=launchpad
environment=HOME="/home/launchpad",REDIS_HOST="127.0.0.1",PATH="/home/launchpad/.config/herd-lite/bin:/home/launchpad/.local/bin:/usr/local/bin:/usr/bin:/bin"
redirect_stderr=true
stdout_logfile=/home/launchpad/projects/foundry/storage/logs/supervisor-horizon.log
stopwaitsecs=10
```

### Managing Horizon

```bash
# Check status of all supervised processes
sudo supervisorctl status

# Restart specific Horizon instance
sudo supervisorctl restart launchpad-horizon
sudo supervisorctl restart foundry-horizon

# View logs
tail -f ~/.config/launchpad/web/storage/logs/supervisor-horizon.log
tail -f ~/projects/foundry/storage/logs/supervisor-horizon.log

# Reload supervisor after config changes
sudo supervisorctl reread
sudo supervisorctl update
```


### API Endpoints (Web App)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/projects` | POST | Create new project (dispatches CreateProjectJob) |
| `/api/projects` | GET | List all projects |
| `/api/status` | GET | Get launchpad status |

**Create Project Request:**
```bash
curl -sk https://launchpad.ccc/api/projects \
  -H "Content-Type: application/json" \
  -X POST \
  -d '{"name":"my-project","template":"user/repo","db_driver":"pgsql","visibility":"private"}'
```

**Response:**
```json
{
  "success": true,
  "status": "provisioning",
  "slug": "my-project",
  "message": "Project provisioning started."
}
```

### Troubleshooting Queue Issues

**Jobs not being processed:**
```bash
# Check if Horizon is running
sudo supervisorctl status launchpad-horizon

# Check Redis connectivity from host
redis-cli -h 127.0.0.1 ping

# Check pending jobs
redis-cli -h 127.0.0.1 LLEN launchpad_horizon:default
```

**Job fails with "command not found":**
- Ensure PATH in supervisord config includes all required directories
- Check that `.bun/bin`, `.local/bin`, and `.config/herd-lite/bin` are in PATH

**Job fails with exit code 1:**
- Check Laravel logs: `tail -f ~/.config/launchpad/web/storage/logs/laravel.log`
- Run provision command directly to see full error output


## PHP Container Permissions (Non-Root)

As of v0.0.20, PHP containers run as a non-root user to ensure proper file ownership.

### Why Non-Root?

When FrankenPHP ran as root, files created by PHP (view cache, logs, sessions) were owned by `root:root`. This caused permission issues when:
- Running artisan commands as the launchpad user
- Accessing files created by web requests
- Deploying or updating code

### Container User Configuration

The PHP Dockerfiles (`stubs/php/Dockerfile.php8x`) create a `launchpad` user matching the host:

```dockerfile
# Create launchpad user with same UID/GID as host (1001:1001)
ARG USER_ID=1001
ARG GROUP_ID=1001
ARG DOCKER_GID=988

RUN groupadd -g ${GROUP_ID} launchpad && \
    useradd -u ${USER_ID} -g ${GROUP_ID} -m -s /bin/bash launchpad && \
    groupadd -g ${DOCKER_GID} dockerhost && \
    usermod -aG dockerhost launchpad

# Set ownership of FrankenPHP directories
RUN mkdir -p /data /config && \
    chown -R launchpad:launchpad /data /config

# Create launchpad config directory
RUN mkdir -p /home/launchpad/.config/launchpad && \
    chown -R launchpad:launchpad /home/launchpad/.config

USER launchpad
```

### Key Details

| Setting | Value | Purpose |
|---------|-------|---------|
| UID | 1001 | Matches host `launchpad` user |
| GID | 1001 | Matches host `launchpad` group |
| Docker GID | 988 | Allows docker socket access for status checks |
| Config mount | `/home/launchpad/.config/launchpad` | Container reads launchpad config |

### Docker Socket Access

The container user is added to a `dockerhost` group (GID 988) to access `/var/run/docker.sock`. This enables:
- `launchpad status --json` from within containers
- Docker container inspection for health checks

### PhpComposeGenerator

The `PhpComposeGenerator` service mounts the config directory appropriately:

```php
// Mount the launchpad config directory to /home/launchpad/.config/launchpad
// This allows the CLI web app (running as launchpad user in FrankenPHP) to read the config
if (File::isDirectory($configPath)) {
    return "      - {$configPath}:/home/launchpad/.config/launchpad:ro\n";
}
```

### Verifying Container User

```bash
# Check which user is running in the container
docker exec launchpad-php-85 whoami
# Output: launchpad

# Verify UID/GID
docker exec launchpad-php-85 id
# Output: uid=1001(launchpad) gid=1001(launchpad) groups=1001(launchpad),988(dockerhost)

# Verify docker socket access
docker exec launchpad-php-85 docker ps --format "{{.Names}}" | head -3
```

### Migration from Root Containers

If upgrading from a version that ran as root, rebuild containers:

```bash
cd ~/.config/launchpad/php
docker compose build --no-cache
docker compose up -d
```

Existing files owned by root can be fixed with:
```bash
sudo chown -R launchpad:launchpad ~/projects/*/storage
```

### Reverb Broadcasting Configuration

**The Problem:** The web app runs in two contexts that need different Reverb connections:
1. **PHP container** - Can reach Docker network (`launchpad-reverb:6001`)
2. **Horizon on HOST** - Can reach localhost (`127.0.0.1:6001`)

Both read the same `.env` file, so we use environment variable overrides.

**Solution:**

1. **Web app `.env`** - Configured for Docker container:
   ```env
   REVERB_HOST=launchpad-reverb
   REVERB_PORT=6001
   REVERB_SCHEME=http
   ```

2. **Supervisord config** - Overrides for Horizon on HOST:
   ```ini
   # /etc/supervisor/conf.d/launchpad-horizon.conf
   environment=...,REVERB_HOST="127.0.0.1",REVERB_PORT="6001",REVERB_SCHEME="http",...
   ```

Environment variables from supervisord override `.env` values because PHP checks actual env vars before loading `.env`.

**Safety net:** Both `CreateProjectJob` and `DeleteProjectJob` wrap `event()` in try/catch to prevent broadcast failures from failing the job.

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

#### ProvisionContext DTO

Immutable context object passed through all provisioning actions:

```php
use App\Data\Provision\ProvisionContext;

$context = new ProvisionContext(
    slug: 'my-project',
    projectPath: '/home/launchpad/projects/my-project',
    githubRepo: 'user/my-project',      // optional
    cloneUrl: 'git@github.com:...',     // optional
    template: 'user/template',          // optional
    visibility: 'private',              // default: private
    phpVersion: '8.4',                  // optional, auto-detected
    dbDriver: 'pgsql',                  // optional: sqlite, pgsql
    sessionDriver: 'redis',             // optional: file, database, redis
    cacheDriver: 'redis',               // optional: file, database, redis
    queueDriver: 'redis',               // optional: sync, database, redis
    minimal: false,                     // skip npm/build/env/migrations
    fork: false,                        // fork instead of import
    displayName: 'My Project',          // optional APP_NAME
    tld: 'ccc',                         // default: ccc
);

// Helper methods
$context->getHomeDir();    // Returns HOME directory
$context->getPhpEnv();     // Returns env array for Process calls
```

#### StepResult DTO

Result object returned by all actions:

```php
use App\Data\Provision\StepResult;

// Success
return StepResult::success();
return StepResult::success(['phpVersion' => '8.4']);

// Failure
return StepResult::failed('Error message');

// Check result
if ($result->isSuccess()) { ... }
if ($result->isFailed()) { ... }
$result->error;  // Error message (null if success)
$result->data;   // Data array (empty if none)
```

#### ProvisionLogger Service

Unified logging to file, command output, and Reverb WebSocket:

```php
use App\Services\ProvisionLogger;

$logger = new ProvisionLogger(
    broadcaster: $reverbBroadcaster,  // optional
    command: $this,                   // optional Command instance
    slug: 'my-project',               // required for log file
);

$logger->info('Installing dependencies...');
$logger->warn('npm had warnings');
$logger->error('Build failed');
$logger->log('Debug message');  // File only, no command output

// Broadcast status to WebSocket
$logger->broadcast('installing_composer');
$logger->broadcast('failed', 'Error details');
```

Log files are stored at: `~/.config/launchpad/logs/provision/{slug}.log`

### Action Pattern

Each action follows this pattern:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;

final readonly class MyAction
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        // Early return if not applicable
        if (! file_exists("{$context->projectPath}/some-file")) {
            $logger->info('Skipping - not applicable');
            return StepResult::success();
        }

        $logger->info('Doing the thing...');

        // Log details for debugging
        $logger->log("Working on: {$context->projectPath}");

        // Do work...
        $result = Process::path($context->projectPath)
            ->env($context->getPhpEnv())
            ->timeout(60)
            ->run('some-command');

        // Log output for debugging
        $logger->log("Exit code: {$result->exitCode()}");
        if ($result->errorOutput()) {
            $logger->log("stderr: {$result->errorOutput()}");
        }

        if (! $result->successful()) {
            return StepResult::failed("Command failed: {$result->errorOutput()}");
        }

        $logger->info('Completed successfully');
        return StepResult::success(['key' => 'value']);
    }
}
```

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

### Using Actions in Commands

```php
use App\Actions\Provision\ConfigureEnvironment;
use App\Actions\Provision\GenerateAppKey;

public function handle(): int
{
    $context = new ProvisionContext(...);
    $logger = new ProvisionLogger(slug: $context->slug);

    // Run action via container
    $result = app(ConfigureEnvironment::class)->handle($context, $logger);
    
    if ($result->isFailed()) {
        $this->error($result->error);
        return 1;
    }

    // Chain actions
    $result = app(GenerateAppKey::class)->handle($context, $logger);
    if ($result->isFailed()) {
        throw new \RuntimeException($result->error);
    }

    // Access result data
    $phpVersion = $result->data['phpVersion'] ?? '8.5';

    return 0;
}
```

## Testing

### Test Setup

Tests use Pest PHP with isolated test databases and temporary project directories.

**Configuration:**
- `phpunit.xml` - Sets `LAUNCHPAD_TEST_DB` environment variable
- `tests/Pest.php` - Helper functions for test setup
- `tests/database/` - Directory for test SQLite databases

### Helper Functions

```php
// Create a temporary Laravel project structure
$projectPath = createTestProject('my-test-project');
// Creates: /tmp/launchpad-tests/my-test-project/
// With: .env.example, artisan, composer.json, bootstrap/app.php

// Clean up after test
deleteDirectory($projectPath);

// Get fresh test database
$db = testDatabase();

// Clean up all test projects
cleanupTestProjects();
```

### Writing Action Tests

```php
<?php

use App\Actions\Provision\ConfigureEnvironment;
use App\Data\Provision\ProvisionContext;
use App\Services\ProvisionLogger;

beforeEach(function () {
    $this->projectPath = createTestProject('test-env');
    $this->logger = new ProvisionLogger(slug: 'test-env');
});

afterEach(function () {
    deleteDirectory($this->projectPath);
});

it('configures PostgreSQL database driver', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        dbDriver: 'pgsql',
    );
    
    $action = new ConfigureEnvironment();
    $result = $action->handle($context, $this->logger);
    
    expect($result->isSuccess())->toBeTrue();
    
    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('DB_CONNECTION=pgsql');
    expect($env)->toContain('DB_HOST=launchpad-postgres');
});

it('fails when .env file cannot be written', function () {
    // Make directory read-only
    chmod($this->projectPath, 0444);
    
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
    );
    
    $action = new ConfigureEnvironment();
    $result = $action->handle($context, $this->logger);
    
    expect($result->isFailed())->toBeTrue();
    
    // Restore permissions for cleanup
    chmod($this->projectPath, 0755);
});
```

### Running Tests

```bash
# Run all unit tests
./vendor/bin/pest tests/Unit

# Run specific test file
./vendor/bin/pest tests/Unit/ConfigureEnvironmentTest.php

# Run with coverage
./vendor/bin/pest tests/Unit --coverage
```

### Test Files

| Test File | Tests | Coverage |
|-----------|-------|----------|
| `ConfigureEnvironmentTest.php` | 12 | .env setup, drivers, Redis |
| `ConfigureTrustedProxiesTest.php` | 5 | Laravel 11+ trusted proxies |
| `DatabaseServiceTest.php` | 10 | PHP version storage |
| `GenerateAppKeyTest.php` | 3 | APP_KEY edge cases |
| `ProvisionContextTest.php` | 5 | Context DTO |
| `ProvisionLoggerTest.php` | 6 | Logging service |
| `SetPhpVersionTest.php` | 7 | PHP version detection |
| `StepResultTest.php` | 3 | Result DTO |

### Testing Actions That Use Process Facade

Actions that use `Illuminate\Support\Facades\Process` require Laravel to be bootstrapped. For unit tests:

1. **Test edge cases** that don't invoke Process (missing files, validation)
2. **Use Feature tests** for full integration testing with the CLI
3. **Mock Process** for specific scenarios (requires TestCase that extends LaravelZero TestCase)

```php
// Unit test - test validation without Process
it('skips when no artisan file exists', function () {
    unlink("{$this->projectPath}/artisan");
    
    $action = new GenerateAppKey();
    $result = $action->handle($this->context, $this->logger);
    
    expect($result->isSuccess())->toBeTrue();
});

// Feature test - full integration (uses real Process)
it('generates app key in real project', function () {
    // This would run the actual artisan command
})->skip('Requires real Laravel project');
```
