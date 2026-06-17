#!/usr/bin/env bash
# Framix Blocks — Tailwind build engine (project-agnostic, offline-capable).
#
# PURE: given -i <input.css> -o <output.css>, compiles via the Tailwind v4 CLI.
# No site.yml / FRX_* knowledge — callers resolve paths and cd into the layer.
# Toolchain resolution, in order (relative to the current working directory):
#   1. node_modules/.bin/tailwindcss  (local `npm install`, pinned)
#   2. npx -y @tailwindcss/cli@$TW_CLI_VERSION  (FramixOS: OFFLINE from the
#      runner image's pre-warmed npx cache; local: downloads on first use)
# TW_CLI_VERSION is EXACT — the platform runner is egress-denied; a bare/@latest
# invocation can still hit a dist-tag lookup and fail. Frozen cross-repo contract:
# keep in lockstep with the wp-agent scaffold package.json, the wp-agent
# tw-build.sh wrapper default, and the FramixOS runner-image pre-warm.
# Servers never build — the output CSS is committed and deploys as plain code.
set -euo pipefail

TW_CLI_VERSION="${FRX_TW_CLI_VERSION:-4.3.0}"
IN="" OUT="" WATCH=0
while [ $# -gt 0 ]; do case "$1" in
  -i)      IN="$2"; shift 2 ;;
  -o)      OUT="$2"; shift 2 ;;
  --watch) WATCH=1; shift ;;
  *) echo "framix-blocks tw-build: unknown argument '$1'" >&2; exit 1 ;;
esac; done

[ -n "$IN" ] && [ -n "$OUT" ] || {
  echo "usage: tw-build.sh -i <input.css> -o <output.css> [--watch]" >&2; exit 1; }
[ -f "$IN" ] || { echo "framix-blocks tw-build: input not found: $IN" >&2; exit 1; }

ARGS=(-i "$IN" -o "$OUT" --minify)
[ "$WATCH" -eq 1 ] && ARGS+=(--watch)

if [ -x node_modules/.bin/tailwindcss ]; then
  node_modules/.bin/tailwindcss "${ARGS[@]}"
else
  npx -y "@tailwindcss/cli@$TW_CLI_VERSION" "${ARGS[@]}" || {
    echo "framix-blocks tw-build: tailwind CLI unavailable — run 'npm install' in the layer," >&2
    echo "or (platform) ensure the runner image pre-warms @tailwindcss/cli@$TW_CLI_VERSION." >&2
    exit 1
  }
fi
