#!/usr/bin/env sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
STATIC="$ROOT/extension/static"
BUILD="$ROOT/extension/build"
TARGETS="chrome firefox"

if [ ! -d "$BUILD" ]; then
  echo "Missing $BUILD — run: pnpm run build:extension" >&2
  exit 1
fi

for target in $TARGETS; do
  dir="$ROOT/extension/$target"
  mkdir -p "$dir/icons"

  for file in popup.html popup.css content.css options.html; do
    cp "$STATIC/$file" "$dir/$file"
  done

  for file in background.js content.js popup.js options.js; do
    cp "$BUILD/$file" "$dir/$file"
  done

  for size in 16 48 128; do
    if [ ! -f "$dir/icons/icon${size}.png" ]; then
      cp "$ROOT/extension/chrome/icons/icon${size}.png" "$dir/icons/icon${size}.png"
    fi
  done
done

echo "Built extension synced to chrome/ and firefox/"
