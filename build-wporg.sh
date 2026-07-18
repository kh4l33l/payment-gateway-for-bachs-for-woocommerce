#!/bin/bash
#
# Build the WordPress.org submission zip.
#
# Runs Pressship (readme validation + the official Plugin Check), then writes a
# clean, wp.org-installable zip to ~/Downloads/bachs-test/<slug>-<version>-wporg.zip.
# Use this before submitting to, or releasing on, WordPress.org.
#
# For a plain (unvalidated) install zip for local/InstaWP testing, use ./build.sh instead.
#
# Usage: ./build-wporg.sh
#
set -euo pipefail

PLUGIN_SLUG="payment-gateway-for-bachs-for-woocommerce"
MAIN_FILE="${PLUGIN_SLUG}.php"

# Always run from the plugin directory, wherever the script is called from.
cd "$(dirname "$0")"

# Read the version from the plugin header so the filename tracks it automatically.
VERSION=$(awk -F': ' '/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*/{print $2; exit}' "$MAIN_FILE" | tr -d '\r')
if [ -z "$VERSION" ]; then
  echo "Could not determine plugin version from $MAIN_FILE" >&2
  exit 1
fi

OUTPUT_DIR="$HOME/Downloads/bachs-test"
mkdir -p "$OUTPUT_DIR"
ZIP_FILE="$OUTPUT_DIR/${PLUGIN_SLUG}-${VERSION}-wporg.zip"

# Pressship validates (readme + Plugin Check), then writes <slug>.zip in this dir.
PACK_ZIP="$PWD/${PLUGIN_SLUG}.zip"
rm -f "$PACK_ZIP"

echo "Validating and packaging with Pressship..."
if ! npx --no-install pressship pack .; then
  echo "" >&2
  echo "Pressship pack failed." >&2
  echo "If it reports a missing Playwright Chromium, run this once and retry:" >&2
  echo "  npx playwright@1.61.1 install chromium chromium-headless-shell" >&2
  exit 1
fi

if [ ! -f "$PACK_ZIP" ]; then
  echo "Expected package not found: $PACK_ZIP" >&2
  exit 1
fi

mv -f "$PACK_ZIP" "$ZIP_FILE"
echo ""
echo "Created $ZIP_FILE ($(du -h "$ZIP_FILE" | cut -f1))"
