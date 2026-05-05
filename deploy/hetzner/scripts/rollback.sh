#!/usr/bin/env bash
# Roll back to the previous image tag (recorded by CI in env/.previous before
# overwriting .env). Optionally pass an explicit tag: ./rollback.sh <tag>
#
# This script mutates .env in-place to swap IMAGE_TAG, then calls deploy.sh.
# The next CI deploy will re-render .env from scratch, so this in-place edit is
# only relevant until the next workflow run.

set -euo pipefail

ROOT="${VFC_ROOT:-/srv/virtua-fc}"
ENV_FILE="${VFC_ENV_FILE:-$ROOT/env/.env}"
PREV_FILE="$ROOT/env/.previous"

TARGET="${1:-}"
if [ -z "$TARGET" ]; then
    if [ ! -f "$PREV_FILE" ]; then
        echo "No previous tag recorded at $PREV_FILE — pass one explicitly: ./rollback.sh <tag>" >&2
        exit 1
    fi
    TARGET=$(cat "$PREV_FILE")
fi

if [ ! -f "$ENV_FILE" ]; then
    echo "Missing env file: $ENV_FILE" >&2
    exit 1
fi

echo "==> Rolling back to $TARGET"
sed -i.bak "s|^IMAGE_TAG=.*|IMAGE_TAG=$TARGET|" "$ENV_FILE"
rm -f "$ENV_FILE.bak"

"$(dirname "$0")/deploy.sh"
