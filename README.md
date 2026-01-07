# Launchpad CLI

A local PHP development environment powered by Docker. Launchpad provides a simple, fast way to run PHP applications locally with automatic HTTPS, multiple PHP versions, and essential services.

## Features

- **Multiple PHP Versions** - Run PHP 8.3 and 8.4 side-by-side via FrankenPHP
- **Automatic HTTPS** - Local SSL certificates via Caddy
- **Essential Services** - PostgreSQL, Redis, and Mailpit included
- **Simple DNS** - Automatic `.test` domain resolution
- **Per-site PHP** - Configure PHP version per project

## Installation

Download the latest release:

```bash
curl -L -o ~/.local/bin/launchpad https://github.com/nckrtl/launchpad-cli/releases/latest/download/launchpad.phar
chmod +x ~/.local/bin/launchpad
```

Make sure `~/.local/bin` is in your PATH.

## Quick Start

1. Initialize Launchpad (first time only):
   ```bash
   launchpad init
   ```

2. Start the services:
   ```bash
   launchpad start
   ```

3. Trust the local CA certificate (for HTTPS):
   ```bash
   launchpad trust
   ```

4. Link your project (creates a symlink in ~/projects):
   ```bash
   ln -s /path/to/your/project ~/projects/myapp
   ```

5. Visit https://myapp.test in your browser!

## Commands

| Command | Description |
|---------|-------------|
| `launchpad init` | First-time setup: creates config, pulls images, sets up DNS |
| `launchpad start` | Start all Launchpad services |
| `launchpad stop` | Stop all Launchpad services |
| `launchpad restart` | Restart all Launchpad services |
| `launchpad status` | Show status and running services |
| `launchpad sites` | List all sites with their PHP versions |
| `launchpad php <site> <version>` | Set PHP version for a site (8.3 or 8.4) |
| `launchpad logs` | Tail container logs |
| `launchpad trust` | Install Caddy root CA for local HTTPS |
| `launchpad upgrade` | Upgrade to the latest version |
| `launchpad rebuild` | Rebuild PHP images with Redis and other extensions |
| `launchpad upgrade --check` | Check for available updates |
| `launchpad worktrees` | List all git worktrees |
| `launchpad worktree:refresh` | Auto-detect and link new worktrees |
| `launchpad worktree:unlink <site> <wt>` | Remove worktree routing |
| `launchpad project:create <name>` | Create project with async provisioning |
| `launchpad provision <slug>` | Background provisioning (internal) |

## Services & Ports

| Service | Port(s) | Description |
|---------|---------|-------------|
| Caddy | 80, 443 | Web server with automatic HTTPS |
| PHP 8.3 | - | FrankenPHP worker |
| PHP 8.4 | - | FrankenPHP worker |
| PostgreSQL | 5432 | Database server |
| Redis | 6379 | Cache server |
| Mailpit | 1025, 8025 | Mail catcher (SMTP: 1025, Web UI: 8025) |
| DNS | 53 | Local DNS resolver for .test domains |

## Configuration

Launchpad stores its configuration at `~/.config/launchpad/config.json`. You can customize:

### Paths

Add directories to scan for projects:

```json
{
  "paths": ["~/projects", "~/clients"]
}
```

### Custom Site Paths

Override the auto-detected path for any site. Useful for nested projects:

```json
{
  "sites": {
    "mysite": {
      "path": "~/projects/monorepo/apps/mysite"
    }
  }
}
```

### Default PHP Version

```json
{
  "default_php_version": "8.4"
}
```

### TLD

Change the top-level domain (default: `test`):

```json
{
  "tld": "local"
}
```


## Git Worktree Support

Launchpad automatically detects git worktrees and creates subdomains:

```bash
# Create a worktree for your project
cd ~/projects/myapp
git worktree add ../myapp-feature-auth feature/auth

# Refresh to pick up the new worktree
launchpad worktree:refresh

# Access via subdomain
# https://feature-auth.myapp.test
```

Worktrees are served from `<worktree-name>.<site>.test`.

## Requirements

- Docker
- macOS or Linux

## Development

### Setup

```bash
git clone https://github.com/nckrtl/launchpad-cli.git
cd launchpad-cli
composer install

# Enable git hooks
git config core.hooksPath .githooks
```

### Quality Tools

| Tool | Command | Description |
|------|---------|-------------|
| PHPStan | `./vendor/bin/phpstan analyse` | Static analysis (level 5) |
| Rector | `./vendor/bin/rector` | Automated refactoring |
| Pint | `./vendor/bin/pint` | Code formatting |
| Pest | `./vendor/bin/pest` | Test suite |

### Running Checks

```bash
# Run all checks (same as pre-commit hook)
./vendor/bin/rector --dry-run
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pest
```

### Pre-commit Hook

The project includes a pre-commit hook that runs all quality checks before each commit. Enable it with:

```bash
git config core.hooksPath .githooks
```

### CI

GitHub Actions runs the full quality check suite on every push and PR to main.

## License

MIT License
