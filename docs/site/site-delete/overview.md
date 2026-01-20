# site:delete overview

Deletes a site including its local files and database, with support for database-driven lookups and duplicate slug handling.

## Process

1. Resolves site location using database or filesystem scan:
   - If `--id` provided: looks up site by ID (unique, unambiguous)
   - If slug provided: queries database for matching sites
   - If multiple sites share the same slug: prompts user to choose
   - If no database entry: falls back to filesystem scan via SiteScanner
2. Confirms deletion (unless `--force` is provided)
3. Drops the PostgreSQL database (reads DB_DATABASE from .env, or uses slug as fallback)
4. Deletes the local directory (with sudo fallback for container-created files)
5. Removes the site record from the database
6. Regenerates and reloads Caddy configuration

## Site Path Storage

Sites are stored in the database with their paths during `site:list` or `site:scan` operations. This enables:

- Fast lookups without filesystem scanning
- Handling of moved sites (rescan if stored path is invalid)
- Support for sites with duplicate slugs in different paths
- Automatic cleanup of orphan database entries when sites are removed from disk

## Failure and recovery paths

- If `--id` is provided but site not found: exits with error
- If slug not found in database: attempts filesystem scan as fallback
- If directory deletion fails (permissions): falls back to sudo
- Database cleanup happens regardless of file deletion success
- Non-PostgreSQL databases (MySQL, SQLite) are skipped with a warning

## Inputs and options

| Option | Description |
|--------|-------------|
| `slug` | Site slug to delete (positional argument) |
| `--slug` | Site slug to delete (alternative to positional) |
| `--id` | Site ID from database (for unambiguous deletion) |
| `--force` | Skip confirmation prompts |
| `--keep-db` | Do not drop the PostgreSQL database |
| `--json` | Output as JSON |

## Usage examples

```bash
# Delete by slug (interactive confirmation)
orbit site:delete my-site

# Delete by slug with force
orbit site:delete my-site --force

# Delete by database ID (useful when multiple sites share a slug)
orbit site:delete --id=42 --force

# Keep the database, only delete files
orbit site:delete my-site --force --keep-db

# JSON output for scripting
orbit site:delete my-site --force --json
```

## Key integrations

- DatabaseService (site path storage, ID lookups, duplicate handling)
- SiteScanner (filesystem fallback, path validation)
- CaddyfileGenerator (config regeneration)
- Docker PostgreSQL (database drop)
- ReverbBroadcaster (WebSocket status updates)
