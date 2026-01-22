# site:create overview

Creates a new site by dispatching a provisioning job to Horizon via the orbit-core API. All provisioning logic runs in `CreateSiteJob` in orbit-core.

## Architecture

```
CLI (site:create)
    │
    ▼
POST /api/sites (orbit-core)
    │
    ▼
CreateSiteJob (Horizon queue)
    │
    ├── Repository operations (clone/fork/template)
    ├── Dependency installation (composer, npm/bun)
    ├── Environment configuration
    ├── Database setup
    └── Caddy reload
```

## Flows

| Scenario | Command | Behavior |
|----------|---------|----------|
| Contribute to project | `site:create my-app --clone=user/repo` | Clone repo, origin points to source |
| Contribute via your copy | `site:create my-app --clone=user/repo --fork` | Fork to your account, clone your fork |
| Use GitHub template | `site:create my-app --template=org/template` | Create new repo from template |

## Process

1. CLI validates the site name (rejects reserved name "orbit")
2. CLI builds API payload from options
3. CLI sends POST request to `https://orbit.{tld}/api/sites`
4. API creates Site record and dispatches `CreateSiteJob` to Horizon
5. CLI returns immediately with "queued" status (or polls if `--wait` flag)

### Job execution (in orbit-core)

The `CreateSiteJob` handles all provisioning:

1. Create project directory
2. Repository operations (clone, fork, or create from template)
3. Run ProvisionPipeline:
   - Install composer dependencies
   - Detect and install Node dependencies (bun or npm)
   - Build assets
   - Configure environment (.env)
   - Create database
   - Generate app key
   - Run migrations
   - Set PHP version
4. Detect site type (laravel-app, laravel-package, cli, web)
5. Broadcast ready status via WebSocket
6. Regenerate Caddyfile and reload Caddy

## Failure and recovery paths

- Job failures update Site status to 'failed' with error message
- Errors are broadcast via WebSocket for real-time UI updates
- Empty project directories are cleaned up on failure
- The `--wait` flag will report failures after polling

## Inputs and options

| Option | API Key | Description |
|--------|---------|-------------|
| `name` (required) | `name` | Site name |
| `--clone` | `template` + `is_template=false` | Repository to clone |
| `--template` | `template` + `is_template=true` | GitHub template repository |
| `--fork` | `fork` | Fork instead of clone (only with `--clone`) |
| `--organization` | `org` | GitHub organization for new repos |
| `--visibility` | `visibility` | Repository visibility (private/public) |
| `--php` | `php_version` | PHP version (8.3, 8.4, 8.5) |
| `--db-driver` | `db_driver` | Database driver (sqlite, pgsql) |
| `--session-driver` | `session_driver` | Session driver (file, database, redis) |
| `--cache-driver` | `cache_driver` | Cache driver (file, database, redis) |
| `--queue-driver` | `queue_driver` | Queue driver (sync, database, redis) |
| `--wait` | (CLI only) | Poll for completion instead of returning immediately |
| `--json` | (CLI only) | Output as JSON for programmatic use |

## URL normalization

The CLI normalizes git URLs to `owner/repo` format:

- `git@github.com:user/repo.git` → `user/repo`
- `https://github.com/user/repo` → `user/repo`
- `user/repo` → `user/repo` (unchanged)

## Key integrations

- **orbit-core API**: Receives site creation requests, dispatches jobs
- **Laravel Horizon**: Processes provisioning jobs in background
- **Laravel Reverb**: Broadcasts real-time status updates via WebSocket
- **GitHub (gh cli)**: Repository operations (clone, fork, template)
- **Caddy**: HTTPS configuration via `orbit caddy:reload`
