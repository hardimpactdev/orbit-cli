# site:create overview

Creates and provisions a new site with one of four explicit flows: composer create-project, clone, fork, or template.

## Flows

| Scenario | Command | Behavior |
|----------|---------|----------|
| Start new project | `site:create my-app laravel/laravel` | `composer create-project {package}` |
| Contribute to project | `site:create my-app --clone=user/repo` | Clone repo, origin points to source |
| Contribute via your copy | `site:create my-app --clone=user/repo --fork` | Fork to your account, clone your fork |
| Use GitHub template | `site:create my-app --template=org/template` | Create new repo from template |

## Process

- Validates the site name and resolves project path.
- If `--path` provided: uses pre-created directory (fails if missing).
- If no `--path`: creates directory at default location (fails if exists).
- Builds a SiteCreateData DTO from CLI options (repo, template, visibility, PHP, drivers, etc.).
- For package flow: validates package exists on Packagist, runs `composer create-project`.
- For clone flow: clones the repository, keeping origin pointing to source (contributor workflow).
- For fork flow: forks the repo to your account, then clones your fork.
- For template flow: creates new repo from GitHub template, then clones.
- Runs project setup via ProvisionPipeline:
  1. Install composer dependencies
  2. Detect Node package manager (bun or npm based on lock file)
  3. Install Node dependencies (using detected package manager)
  4. Build assets (using detected package manager)
  5. Configure environment (.env)
  6. Create database
  7. Generate app key
  8. Run migrations
  9. Configure trusted proxies
  10. Set PHP version
  11. Regenerate Caddyfile and reload Caddy (via `caddy:reload`)
- Broadcasts ready status and returns JSON success.

## Failure and recovery paths

- Any action failure throws and is caught in the command handler.
- Errors are logged and broadcast as failed, with optional JSON error output.
- Empty project directories are removed during cleanup.

## Inputs and options

- Required: `name`
- Optional: `package` (Packagist package for composer create-project)
- Repository options: `--clone`, `--fork`, `--template`, `--github-repo`
- Common options: `--organization`, `--visibility`
- Environment setup: `--php`, `--db-driver`, `--session-driver`, `--cache-driver`, `--queue-driver`
- Execution mode: `--minimal` (composer only), `--json`
- Path override: `--path` (use pre-created directory, skip mkdir - for web flow)

## Key integrations

- Packagist for package validation (create-project flow)
- GitHub (gh cli) for repo fork/template and cloning
- Caddy config generation and reload via `orbit caddy:reload` (regenerates Caddyfile, reloads Caddy)
