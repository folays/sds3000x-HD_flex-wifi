#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
IMAGE_NAME="flex-wifi-builder"

echo "=== Building container ==="
podman build -t "$IMAGE_NAME" "$SCRIPT_DIR"

echo "=== Extracting wifi_addon.zip ==="
CID=$(podman create "$IMAGE_NAME")
podman cp "$CID:/wifi_addon.zip" "$SCRIPT_DIR/wifi_addon.zip"
podman rm "$CID"

echo "=== Done ==="
sha256sum "$SCRIPT_DIR/wifi_addon.zip"
ls -lh "$SCRIPT_DIR/wifi_addon.zip"
