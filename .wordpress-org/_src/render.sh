#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/_src"
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
OUT="$ROOT"

render() {
  local html="$1"
  local out="$2"
  local w="$3"
  local h="$4"
  local tmp="${out}.tmp.png"
  rm -f "$tmp" "$out"
  "$CHROME" \
    --headless=new \
    --disable-gpu \
    --hide-scrollbars \
    --force-device-scale-factor=1 \
    --default-background-color=FFFFFFFF \
    --window-size="${w},${h}" \
    --screenshot="$tmp" \
    "file://${html}" >/dev/null 2>&1
  # Crop/pad to exact size if Chrome returned something slightly different.
  sips -z "$h" "$w" "$tmp" --out "$out" >/dev/null
  # Flatten any alpha onto white so WordPress.org gets opaque RGB PNGs.
  sips -s format png --setProperty formatOptions default "$out" >/dev/null 2>&1 || true
  # Re-assert exact size after format ops.
  sips -z "$h" "$w" "$out" >/dev/null
  rm -f "$tmp"
  sips -g pixelWidth -g pixelHeight -g hasAlpha "$out"
}

echo "Rendering icons..."
render "${SRC}/icon.html?size=128" "${OUT}/icon-128x128.png" 128 128
render "${SRC}/icon.html?size=256" "${OUT}/icon-256x256.png" 256 256

echo "Rendering banners..."
render "${SRC}/banner.html?w=772&h=250" "${OUT}/banner-772x250.png" 772 250
render "${SRC}/banner.html?w=1544&h=500" "${OUT}/banner-1544x500.png" 1544 500

echo "Rendering screenshots..."
render "${SRC}/screenshot-1.html" "${OUT}/screenshot-1.png" 1280 800
render "${SRC}/screenshot-2.html" "${OUT}/screenshot-2.png" 1280 800
render "${SRC}/screenshot-3.html" "${OUT}/screenshot-3.png" 1280 800

echo "Done."
ls -la "$OUT"/*.png
