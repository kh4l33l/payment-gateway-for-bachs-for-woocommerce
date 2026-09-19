#!/bin/bash
#
# Build a clean, distributable zip of the plugin into ~/Downloads/bachs-test/.
# The zip contains a single top-level plugin folder and excludes all dev files
# (matching .distignore), ready to upload to WordPress or submit to wp.org.
#
# Usage: ./build.sh
#
set -euo pipefail

PLUGIN_SLUG="payment-gateway-for-bachs-for-woocommerce"
MAIN_FILE="${PLUGIN_SLUG}.php"

# Always run from the plugin directory, wherever the script is called from.
cd "$(dirname "$0")"

if ! command -v rsync >/dev/null 2>&1; then
  echo "rsync is required to build the zip." >&2
  exit 1
fi

# Read the version from the plugin header so the filename tracks it automatically.
VERSION=$(awk -F': ' '/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*/{print $2; exit}' "$MAIN_FILE" | tr -d '\r')
if [ -z "$VERSION" ]; then
  echo "Could not determine plugin version from $MAIN_FILE" >&2
  exit 1
fi

OUTPUT_DIR="$HOME/Downloads/bachs-test"
mkdir -p "$OUTPUT_DIR"
ZIP_FILE="$OUTPUT_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
rm -f "$ZIP_FILE"

# Stage a clean copy in a temp dir so the archive has one top-level plugin folder.
BUILD_DIR=$(mktemp -d)
trap 'rm -rf "$BUILD_DIR"' EXIT
STAGING_DIR="$BUILD_DIR/$PLUGIN_SLUG"
mkdir -p "$STAGING_DIR"

rsync -a ./ "$STAGING_DIR"/ \
  --exclude ".git/" \
  --exclude ".github/" \
  --exclude ".wordpress-org/" \
  --exclude "graphify-out/" \
  --exclude "node_modules/" \
  --exclude "vendor/" \
  --exclude "tests/" \
  --exclude "*.zip" \
  --exclude "*.sh" \
  --exclude "*.map" \
  --exclude "*.log" \
  --exclude ".distignore" \
  --exclude ".pressshipignore" \
  --exclude ".gitignore" \
  --exclude ".gitattributes" \
  --exclude ".DS_Store" \
  --exclude "README.md" \
  --exclude "CLAUDE.md" \
  --exclude "composer.json" \
  --exclude "composer.lock" \
  --exclude "package.json" \
  --exclude "package-lock.json"

( cd "$BUILD_DIR" && zip -rq "$ZIP_FILE" "$PLUGIN_SLUG" -x "*/.DS_Store" )

echo "Created $ZIP_FILE ($(du -h "$ZIP_FILE" | cut -f1))"
