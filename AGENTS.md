# Agent Instructions

This project uses **bd** (beads) for issue tracking. Run `bd onboard` to get started.

## Project Overview

**Orbit CLI** - Local PHP dev environment powered by Docker. Supports both Linux and macOS.

## Unified Web Dashboard

The web dashboard is now a unified app (`orbit-web`) that is bundled with the CLI as a pre-built archive.

- Source: `hardimpactdev/orbit-web` repository
- Bundle: `stubs/orbit-web-bundle.tar.gz`
- Installed to: `~/.config/orbit/web/` on user machines
- Command: `orbit web:install` extracts the bundle and configures the environment.

When making changes to the web app, edit files in the `orbit-web` repository and rebuild the bundle.

## Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --status in_progress  # Claim work
bd close <id>         # Complete work
bd sync               # Sync with git
```

## Quality Gates

**IMPORTANT:** Every fix must have a test. After tests pass, release immediately.

Run before every commit/release:

```bash
./vendor/bin/rector --dry-run
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pest
```

## Local Development Setup

Dev machine uses symlink (not downloaded PHAR):

```bash
ln -s /home/nckrtl/workspaces/orbit/orbit-cli/orbit ~/.local/bin/orbit
```

Ensure `~/.local/bin` is in PATH (already configured in .bashrc).

## After Making Changes

**IMPORTANT: Always complete the full workflow:**

1. **Test locally**: `./vendor/bin/pest` (if code changed)
2. **Add tests**: Every feature must have Pest tests
3. **Commit changes**: Use descriptive commit message
4. **Push via gh CLI**: `git push`

## Release Workflow

After fixes verified (all tests pass):

1. Bump version if needed
2. Create tag: `git tag v0.x.y`
3. Push tag: `git push origin v0.x.y`
4. GitHub Actions builds PHAR and creates release
5. Verify release assets include `orbit.phar`
6. Update local: `orbit upgrade` (or pull latest for symlink setup)

## Platform Support

Code must work on both Linux and macOS. Use `PlatformService` for OS detection:
- `$platform->isMacOS()` / `$platform->isLinux()`
- Platform-specific adapters in `app/Services/Platform/`

## Known Gotchas

### NEVER Use Path Repositories for orbit-core

**Problem:** Using a local path repository in `composer.json` for `orbit-core` (or any package) breaks CI/CD pipelines.

```json
// NEVER DO THIS - breaks GitHub Actions
"repositories": [
    {
        "type": "path",
        "url": "../orbit-core"
    }
]
```

**Why it fails:**
1. The path `../orbit-core` doesn't exist in GitHub Actions runners
2. The `composer.lock` caches the path-based install location
3. Even after removing the repository config, the lock file still references the path
4. CI fails with "Source path '../orbit-core' is not found"

**Solution:** Always use Packagist for orbit-core:
- Use `"hardimpactdev/orbit-core": "@dev"` or a specific version
- Never add path repositories to composer.json
- If you need to test local changes to orbit-core:
  1. Push orbit-core changes to GitHub first
  2. Run `composer update hardimpactdev/orbit-core` to pull from Packagist
  3. Or use `composer config repositories.local path ../orbit-core` temporarily (but NEVER commit this)

**If CI is broken due to path repository:**
1. Remove the repositories section from composer.json
2. Delete composer.lock
3. Run `composer install` to regenerate lock from Packagist
4. Commit both files

### Bun/Node Package Managers in Background Processes

**Problem:** `bun install` (and potentially other package managers) can hang indefinitely when executed from PHP in background/non-interactive contexts like Laravel Horizon queue workers or launchd services.

**Root Cause:** Package managers often try to display progress bars or interactive output. When there's no TTY (terminal) available, the process can block waiting for terminal operations that will never complete.

**Solution:** Always use CI-mode commands when running package managers from PHP:

```php
// BAD - can hang in background processes
Process::run('bun install');
Process::run('bun install --no-progress');

// GOOD - designed for non-interactive environments
Process::run('bun ci');

// Also set CI environment variable for extra safety
Process::env(['CI' => '1'])->run('bun ci');
```

**Key Points:**
- `bun ci` is specifically designed for CI/non-TTY environments
- Always set `CI=1` environment variable when running from PHP background processes
- This applies to Horizon jobs, queue workers, launchd services, and any PHP subprocess without a TTY
- The issue does NOT occur when running PHP scripts interactively from terminal
- `shell_exec()` and Laravel's `Process::run()` both work fine - the issue is TTY detection in bun
- npm has similar issues; use `npm ci` instead of `npm install` in CI contexts

**Debugging Tips:**
- If bun hangs, test the same command directly in terminal (it will work)
- Check if process is running in Horizon vs direct CLI invocation
- Increase timeout and check logs for partial output
- Use `Process::tty()` if you need interactive mode (but avoid in background jobs)

### Platform-Specific Service Commands

**Problem:** Service management commands differ between Linux and macOS.

**Solution:** Always use `PlatformAdapter` for service operations:

```php
// BAD - Linux only
Process::run('sudo systemctl reload caddy');

// GOOD - cross-platform
$this->phpManager->getAdapter()->reloadCaddy();
```

**macOS uses:**
- `brew services restart caddy`
- `brew services restart php@8.4`

**Linux uses:**
- `sudo systemctl reload caddy`
- `sudo systemctl restart php8.4-fpm`

### PHP-FPM Restart Kills Web Requests

**Problem:** When the CLI is called from the orbit-web dashboard (via PHP-FPM), restarting or reloading PHP-FPM during command execution kills the requesting web process, causing a 502 Bad Gateway.

**Root Cause:** The web app calls CLI commands synchronously. If the CLI triggers `systemctl restart php-fpm` or even `systemctl reload php-fpm`, it disrupts the PHP-FPM worker handling the original request.

**Solution:** Avoid PHP-FPM restarts/reloads during operations that are called from web contexts:
- In `SiteCreateCommand`, the early Caddy reload step should NOT include `reloadPhp()` 
- Only reload Caddy (which is safe) - PHP-FPM reload is not needed for new sites to be accessible
- Use `reloadPhpFpm()` (graceful reload) instead of `restartPhpFpm()` when reload is absolutely necessary

```php
// In SiteCreateCommand - early Caddy reload
$caddyfileGenerator->generate();
$caddyfileGenerator->reload();  // Safe
// $caddyfileGenerator->reloadPhp();  // REMOVED - causes 502 when called from web
```

### JSON Output Must Be Clean

**Problem:** When `--json` flag is used, the web app parses stdout as JSON. Any non-JSON output (console messages, warnings, info lines) corrupts the JSON and causes "Failed to parse JSON: Syntax error" errors.

**Solution:** When `--json` is passed:
1. Pass `null` instead of `$this` command to `ProvisionLogger` to suppress console output
2. Use `NullOutput` for nested `Artisan::call()` invocations
3. All warnings should go to stderr (via `error_log()`), not stdout

```php
// In ProvisionCommand
$this->logger = new ProvisionLogger(
    broadcaster: $broadcaster,
    command: $this->option('json') ? null : $this,  // null suppresses output
    slug: $slug,
);

// In SiteCreateCommand
$output = $this->wantsJson() 
    ? new \Symfony\Component\Console\Output\NullOutput() 
    : $this->output;
$exitCode = Artisan::call('provision', $provisionArgs, $output);
```

### Import Flow for Foreign Repos

**Problem:** When cloning a repo from a different owner (e.g., `hardimpactdev/template` for user `nckrtl`), the import-as-new-repo flow must:
1. Run in the cloned project directory (not current working directory)
2. Remove existing origin before creating new repo

**Solution:** 

```php
private function importAsNewRepo(string $newRepo, string $visibility, string $projectPath): void
{
    // Remove existing origin from clone
    Process::path($projectPath)->run('git remote remove origin');

    // Create new repo from source - MUST use ->path() to run in project directory
    $createResult = Process::timeout(60)
        ->path($projectPath)  // Critical!
        ->run("gh repo create {$newRepo} --{$visibility} --source=. --push");
}
```

### PHP-FPM Pool Configuration

PHP-FPM pool configs are stored in different locations per OS:
- **macOS:** `/opt/homebrew/etc/php/{version}/php-fpm.d/orbit-{version}.conf`
- **Linux:** `/etc/php/{version}/fpm/pool.d/orbit-{version}.conf`

When regenerating configs, ensure:
- Pool names use "orbit-XX" format (not legacy "launchpad-XX")
- Socket paths point to `~/.config/orbit/php/phpXX.sock`
- Log paths point to `~/.config/orbit/logs/phpXX-fpm.log`
- Environment variables include PATH with `~/.bun/bin` for bun access

## Host Services (Systemd/Launchd)

### Horizon Queue Worker

Horizon runs as a systemd service on Linux, processing queues for the bundled web app.

**Service file:** `/etc/systemd/system/orbit-horizon.service`

```ini
[Unit]
Description=Orbit Horizon Queue Worker
After=network.target

[Service]
Type=simple
User=nckrtl
Group=nckrtl
WorkingDirectory=/home/nckrtl/.config/orbit/web
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=5
Environment="PATH=/home/nckrtl/.local/bin:/home/nckrtl/.bun/bin:/usr/local/bin:/usr/bin:/bin"

[Install]
WantedBy=multi-user.target
```

**Commands:**
```bash
sudo systemctl status orbit-horizon   # Check status
sudo systemctl restart orbit-horizon  # Restart
sudo journalctl -u orbit-horizon -f   # View logs
```

**Requirements:**
- The bundled web app (`~/.config/orbit/web`) must have `laravel/horizon` installed
- Redis must be running for queue processing

### Laravel Reverb WebSocket Server

Reverb runs as a Docker container for real-time WebSocket communication.

**Default port:** `8080` (not 6001 - that was Soketi/Pusher's default)

**Configuration:**
- Docker container: `orbit-reverb`
- Port mapping: `8080:8080`
- Caddy proxies `reverb.{tld}` to `localhost:8080`

**Environment variables for client apps:**
```env
REVERB_HOST=reverb.{tld}
REVERB_PORT=443        # Via Caddy TLS proxy
REVERB_SCHEME=https
```

## Landing the Plane (Session Completion)

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd sync
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
