#!/bin/bash

# Extra Chill Docs Sync Script
# Usage: ./scripts/sync.sh [--force]

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(dirname "$SCRIPT_DIR")"
DOCS_DIR="$PLUGIN_ROOT/ec_docs"
UPLOAD_SCRIPT="$SCRIPT_DIR/upload.php"
ENV_FILE="$PLUGIN_ROOT/.env"

# Load environment variables from plugin root .env if it exists
if [ -f "$ENV_FILE" ]; then
    set -a
    source "$ENV_FILE"
    set +a
fi

# Verify password is set
if [ -z "$WP_SYNC_PASSWORD" ]; then
    echo "Error: WP_SYNC_PASSWORD is not set."
    echo "Please create a .env file in $PLUGIN_ROOT with:"
    echo "WP_SITE_URL=https://docs.extrachill.com"
    echo "WP_USERNAME=your_username"
    echo "WP_SYNC_PASSWORD=your_application_password"
    exit 1
fi

php "$UPLOAD_SCRIPT" "$DOCS_DIR" "$@"
