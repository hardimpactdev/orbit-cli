#!/bin/sh
set -e

# Generate APP_KEY if not set
if [ -z "$APP_KEY" ]; then
    export APP_KEY=$(php artisan key:generate --show --no-ansi 2>/dev/null)
fi

# Create/update .env file with Reverb settings
cat > /app/.env << EOF
APP_NAME=LaunchpadReverb
APP_ENV=local
APP_KEY=${APP_KEY}
APP_DEBUG=true

REVERB_APP_ID=${REVERB_APP_ID:-launchpad}
REVERB_APP_KEY=${REVERB_APP_KEY:-launchpad-key}
REVERB_APP_SECRET=${REVERB_APP_SECRET:-launchpad-secret}
REVERB_HOST=${REVERB_HOST:-0.0.0.0}
REVERB_PORT=${REVERB_PORT:-6001}
REVERB_SCHEME=http

BROADCAST_CONNECTION=reverb
EOF

# Clear config cache
php artisan config:clear 2>/dev/null || true

exec "$@"
