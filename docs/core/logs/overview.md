# logs overview

Tails container logs for debugging.

- Shows logs for specified container
- Follows log output by default (like tail -f)
- Press Ctrl+C to stop following

Failure and recovery paths

- Container must exist and have logs

Inputs and options

- container (required): Container name (e.g., orbit-php-83, orbit-caddy)
- --no-follow: Do not follow log output

Key integrations

- DockerManager for log streaming
