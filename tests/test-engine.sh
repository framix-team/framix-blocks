#!/usr/bin/env bash
# Hermetic tests for engine/tw-build.sh — stubbed tailwindcss, no network/node.
set -uo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENGINE="$root/engine/tw-build.sh"
fail=0
ok()  { echo "  ok: $1"; }
bad() { echo "FAIL: $1"; fail=1; }

T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/node_modules/.bin" "$T/src"
echo '@source "x";' > "$T/src/input.css"
# Stub Tailwind CLI: record args, write the -o target.
cat > "$T/node_modules/.bin/tailwindcss" <<'STUB'
#!/usr/bin/env bash
echo "$@" > args.txt
out=""; while [ $# -gt 0 ]; do [ "$1" = "-o" ] && out="$2"; shift; done
mkdir -p "$(dirname "$out")"; echo "/*built*/" > "$out"
STUB
chmod +x "$T/node_modules/.bin/tailwindcss"

# 1. builds via the local bin and ALWAYS injects --minify; writes the output
( cd "$T" && bash "$ENGINE" -i src/input.css -o dist/blocks.css ) >/dev/null 2>&1
[ -f "$T/dist/blocks.css" ] && grep -q -- "-i src/input.css -o dist/blocks.css --minify" "$T/args.txt" \
  && ok "engine builds via local bin and injects --minify" \
  || bad "engine build: args=$(cat "$T/args.txt" 2>/dev/null)"

# 2. --watch is appended after --minify
( cd "$T" && bash "$ENGINE" -i src/input.css -o dist/blocks.css --watch ) >/dev/null 2>&1
grep -q -- "--minify --watch" "$T/args.txt" \
  && ok "engine appends --watch after --minify" \
  || bad "watch: args=$(cat "$T/args.txt" 2>/dev/null)"

# 3. missing -o -> usage error, exit 1
( cd "$T" && bash "$ENGINE" -i src/input.css ) >/dev/null 2>&1
[ $? -eq 1 ] && ok "missing -o refused (exit 1)" || bad "missing -o not refused"

# 4. missing input file -> exit 1
( cd "$T" && bash "$ENGINE" -i nope.css -o dist/x.css ) >/dev/null 2>&1
[ $? -eq 1 ] && ok "missing input refused (exit 1)" || bad "missing input not refused"

# 5. unknown arg -> exit 1
( cd "$T" && bash "$ENGINE" -i src/input.css -o dist/blocks.css --bogus ) >/dev/null 2>&1
[ $? -eq 1 ] && ok "unknown arg refused (exit 1)" || bad "unknown arg not refused"

[ $fail -eq 0 ] && echo "test-engine: ALL OK"
exit $fail
