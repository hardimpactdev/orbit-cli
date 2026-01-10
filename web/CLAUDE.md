# Launchpad Web App

Stateless Laravel API for the Launchpad Desktop app. Runs in a FrankenPHP Docker container and uses Horizon for queue processing.

## Architecture

```
Desktop App                     Remote Server (HOST)
┌─────────────┐               ┌───────────────────────────────────────────────┐
│ Vue Form    │               │                                               │
│     │       │    HTTPS      │  PHP Container (launchpad-php-XX)             │
│     ▼       │ ─────────────►│  └─ This Web App                              │
│ POST /api/  │               │      └─ ApiController / ProjectController     │
│  projects   │               │          └─ dispatch(Job)                     │
└─────────────┘               │                    │ Redis Queue              │
                              │                    ▼                           │
                              │  HOST (supervisord)                           │
                              │  └─ Horizon Worker                            │
                              │      └─ CreateProjectJob / DeleteProjectJob   │
                              │          └─ launchpad CLI command             │
                              └───────────────────────────────────────────────┘
```

**Key Points:**
- Web app runs in Docker container (FrankenPHP)
- Jobs dispatch to Redis queue for operations that need CLI access
- Horizon runs on HOST (not in container) because CLI needs host filesystem access
- Different `REDIS_HOST`: containers use `launchpad-redis`, host uses `127.0.0.1`

## Deployment

Deployed automatically via `launchpad web:install` CLI command:
- Location: `~/.config/launchpad/web/`
- Configuration: `~/.config/launchpad/web/.env`

## API Endpoints

### Projects

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| GET | `/api/projects` | `ApiController@projects` | List all projects |
| POST | `/api/projects` | `ProjectController@store` | Create project (dispatches CreateProjectJob) |
| DELETE | `/api/projects/{slug}` | `ApiController@deleteProject` | Delete project (dispatches DeleteProjectJob) |
| POST | `/api/projects/{slug}/rebuild` | `ApiController@rebuildProject` | Rebuild project |
| GET | `/api/projects/{slug}/provision-status` | `ApiController@provisionStatus` | Get provisioning status |

### Services

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| GET | `/api/status` | `ApiController@status` | Get launchpad service status |
| POST | `/api/start` | `ApiController@start` | Start all services |
| POST | `/api/stop` | `ApiController@stop` | Stop all services |
| POST | `/api/restart` | `ApiController@restart` | Restart all services |
| POST | `/api/services/{service}/start` | `ApiController@startService` | Start specific service |
| POST | `/api/services/{service}/stop` | `ApiController@stopService` | Stop specific service |
| POST | `/api/services/{service}/restart` | `ApiController@restartService` | Restart specific service |
| GET | `/api/services/{service}/logs` | `ApiController@serviceLogs` | Get service logs |

### Configuration

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| GET | `/api/config` | `ApiController@config` | Get launchpad configuration |
| POST | `/api/config` | `ApiController@saveConfig` | Save configuration |
| GET | `/api/sites` | `ApiController@sites` | List all sites |
| GET | `/api/php-versions` | `ApiController@phpVersions` | List available PHP versions |
| GET | `/api/php/{site}` | `ApiController@getPhp` | Get PHP version for site |
| POST | `/api/php/{site}` | `ApiController@setPhp` | Set PHP version for site |
| POST | `/api/php/{site}/reset` | `ApiController@resetPhp` | Reset PHP version to default |

### Worktrees

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| GET | `/api/worktrees` | `ApiController@worktrees` | List all worktrees |
| GET | `/api/worktrees/{site}` | `ApiController@siteWorktrees` | List worktrees for site |
| POST | `/api/worktrees/refresh` | `ApiController@refreshWorktrees` | Refresh worktree list |
| DELETE | `/api/worktrees/{site}/{name}` | `ApiController@unlinkWorktree` | Unlink worktree |

### Workspaces

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| GET | `/api/workspaces` | `ApiController@workspaces` | List all workspaces |
| POST | `/api/workspaces` | `ApiController@createWorkspace` | Create workspace |
| DELETE | `/api/workspaces/{name}` | `ApiController@deleteWorkspace` | Delete workspace |
| POST | `/api/workspaces/{workspace}/projects` | `ApiController@addWorkspaceProject` | Add project to workspace |
| DELETE | `/api/workspaces/{workspace}/projects/{project}` | `ApiController@removeWorkspaceProject` | Remove project from workspace |

### Packages

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| GET | `/api/packages/{app}/linked` | `ApiController@linkedPackages` | Get linked packages |
| POST | `/api/packages/{app}/link` | `ApiController@linkPackage` | Link a package |
| DELETE | `/api/packages/{app}/unlink/{package}` | `ApiController@unlinkPackage` | Unlink a package |

### GitHub

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| GET | `/api/github/user` | `ApiController@githubUser` | Get authenticated GitHub user |
| GET | `/api/github/repo/{owner}/{repo}` | `ApiController@checkRepo` | Check if repo exists |

## Jobs

### CreateProjectJob

Located at `app/Jobs/CreateProjectJob.php`. Dispatched when a project is created via API.

**What it does:**
1. Sets up environment (HOME, PATH)
2. Runs `launchpad provision` CLI command
3. Broadcasts status updates via Reverb (if reachable)

**Critical: PATH Configuration**

The job MUST set the correct PATH for bun and other tools:

```php
$home = $_SERVER['HOME'] ?? '/home/launchpad';
$process = Process::timeout(900)->env([
    'HOME' => $home,
    'PATH' => "{$home}/.bun/bin:" .
              "{$home}/.local/bin:" .
              "{$home}/.config/herd-lite/bin:" .
              "/usr/local/bin:/usr/bin:/bin",
]);
```

**Common PATH Bug:** If you see `$home/home/launchpad/.bun/bin` in the PATH, the string interpolation is wrong. Use `{$home}` syntax, not `$home`.

### DeleteProjectJob

Located at `app/Jobs/DeleteProjectJob.php`. Dispatched when a project is deleted via API.

**What it does:**
1. Runs `launchpad project:delete --slug={slug} --force --json`
2. Broadcasts deletion status via Reverb (if reachable)
3. Optionally deletes GitHub repo (`--delete-repo`) or keeps database (`--keep-db`)

**Why use a job instead of direct execution?**
- CLI binary is not mounted in the PHP container
- Jobs run via Horizon on the HOST where CLI is available
- Same pattern as CreateProjectJob

## Horizon Configuration

Horizon runs on the HOST via supervisord:

```ini
[program:launchpad-horizon]
process_name=%(program_name)s
command=php /home/launchpad/.config/launchpad/web/artisan horizon
autostart=true
autorestart=true
user=launchpad
environment=HOME="/home/launchpad",REDIS_HOST="127.0.0.1"
redirect_stderr=true
stdout_logfile=/home/launchpad/.config/launchpad/web/storage/logs/supervisor-horizon.log
stopwaitsecs=10
```

**Why on HOST, not in container?**
- CLI binary at `~/.local/bin/launchpad` needs access
- Projects created in `~/projects/` (host filesystem)
- Bun/node installed on host, need correct PATH

## Debugging

### Check Horizon Status

```bash
cd ~/.config/launchpad/web && php artisan horizon:status
```

### View Job Logs

```bash
tail -f ~/.config/launchpad/web/storage/logs/laravel.log | grep -E "CreateProjectJob|DeleteProjectJob"
```

### Check for Failed Jobs

```bash
cd ~/.config/launchpad/web && php artisan queue:failed
```

### Restart Horizon

```bash
sudo supervisorctl restart launchpad-horizon
```

### Test API Directly

```bash
# Create project
curl -X POST https://launchpad.ccc/api/projects \
  -H "Content-Type: application/json" \
  -d '{"name": "test-api", "template": "hardimpactdev/liftoff-starterkit", "db_driver": "pgsql", "visibility": "private"}'

# Delete project
curl -X DELETE https://launchpad.ccc/api/projects/test-api

# Check status
curl https://launchpad.ccc/api/status | jq

# List projects
curl https://launchpad.ccc/api/projects | jq
```

## Known Issues

### CLI Commands Fail in Container

If API calls return `{"success":false,"error":"Command failed"}`, the command is likely running in the container where CLI isn't available. Check that:
1. The operation dispatches a Job (for create/delete)
2. Or runs via `executeCommand` which works for read-only operations

### Horizon Using Wrong Redis Host

If Horizon can't connect to Redis, check the supervisor config has `REDIS_HOST="127.0.0.1"` (not `launchpad-redis`).

### Broadcasts Failing

Horizon runs on HOST which doesn't use launchpad DNS. If `reverb.ccc` doesn't resolve, broadcasts fail. This is handled gracefully (non-blocking).
