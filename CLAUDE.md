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
│   ├── InitCommand.php       # Initialize launchpad configuration
│   ├── StartCommand.php      # Start all Docker services
│   ├── StopCommand.php       # Stop all Docker services
│   ├── RestartCommand.php    # Restart all services
│   ├── StatusCommand.php     # Show service status
│   ├── SitesCommand.php      # List registered sites
│   ├── PhpCommand.php        # Set PHP version per site
│   ├── LogsCommand.php       # View service logs
│   └── TrustCommand.php      # Trust the local CA certificate
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
    └── SiteScanner.php          # Scans paths for PHP projects
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
| Commands | StatusCommand, SitesCommand, PhpCommand, StartCommand, StopCommand, RestartCommand, LogsCommand |
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
