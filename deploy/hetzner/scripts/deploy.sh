#!/usr/bin/env bash
# Pull the IMAGE_TAG declared in $ENV_FILE and roll app/horizon/scheduler.
#
# Usage:
#   ./deploy.sh
#
# .env is the source of truth for IMAGE_TAG (and everything else) — it is
# rendered by .github/workflows/deploy.yml on every deploy from GitHub Secrets
# and Variables. CI also records the rollback target in $ROOT/env/.previous
# before invoking this script. rollback.sh writes IMAGE_TAG into .env before
# re-invoking this script.

set -euo pipefail

ROOT="${VFC_ROOT:-/srv/virtua-fc}"
ENV_FILE="${VFC_ENV_FILE:-$ROOT/env/.env}"
COMPOSE="$(dirname "$0")/compose.sh"

if [ ! -f "$ENV_FILE" ]; then
    echo "Missing env file: $ENV_FILE" >&2
    exit 1
fi

IMAGE_TAG=$(grep -E '^IMAGE_TAG=' "$ENV_FILE" | cut -d= -f2- || true)
if [ -z "$IMAGE_TAG" ]; then
    echo "IMAGE_TAG missing from $ENV_FILE" >&2
    exit 1
fi
echo "==> Deploying tag: $IMAGE_TAG"

echo "==> Pulling images"
"$COMPOSE" pull app horizon scheduler

echo "==> Rolling app, horizon, scheduler"
"$COMPOSE" up -d app horizon scheduler

echo "==> Waiting for /up health check"
APP_DOMAIN=$(grep -E '^APP_DOMAIN=' "$ENV_FILE" | cut -d= -f2-)
for i in $(seq 1 30); do
    if curl -fsS --max-time 5 "https://$APP_DOMAIN/up" >/dev/null; then
        echo "==> /up returned 200 (attempt $i)"
        echo "==> Deploy successful: $IMAGE_TAG"
        exit 0
    fi
    sleep 2
done

echo "!! /up did not return 200 within 60s — consider rolling back" >&2
exit 1
