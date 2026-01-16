# Agent Instructions

This project uses **bd** (beads) for issue tracking. Run `bd onboard` to get started.

## Project Overview

**Orbit CLI** - Local PHP dev environment powered by Docker. Supports both Linux and macOS.

## Bundled Web App

The `web/` directory contains a Laravel web app that is bundled with the CLI. This is the dashboard/API that powers the desktop app integration.

- Source: `web/` in this repo
- Installed to: `~/.config/orbit/web/` on user machines
- The CLI copies `web/` during init/upgrade

When making changes to the web app, edit files in `web/` - NOT in `~/.config/orbit/web/` (that's the installed copy).

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
ln -s /home/nckrtl/projects/orbit-cli/orbit ~/.local/bin/orbit
```

Ensure `~/.local/bin` is in PATH (already configured in .bashrc).

## Release Workflow

After fixes verified (all tests pass):

1. Bump version if needed
2. Create tag: `git tag v0.x.y`
3. Push tag: `git push origin v0.x.y`
4. GitHub Actions builds PHAR and creates release
5. Update local: `orbit upgrade` (or pull latest for symlink setup)

## Platform Support

Code must work on both Linux and macOS. Use `PlatformService` for OS detection:
- `$platform->isMac()` / `$platform->isLinux()`
- Platform-specific adapters in `app/Services/Platform/`

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

