#!/usr/bin/env bash
#
# Zero-dependency test runner for framix-blocks.
# Lints every shipped PHP file, runs each tests/test-*.php, then each
# tests/test-*.sh. Non-zero exit on any lint or test failure.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

echo "== php -l (excluding vendor/) =="
while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null
done < <(find . -name '*.php' -not -path './vendor/*' -print0)
echo "lint OK"

status=0
for t in tests/test-*.php; do
	[ -e "$t" ] || continue
	echo
	echo "== $t =="
	if ! php "$t"; then
		status=1
	fi
done

for t in tests/test-*.sh; do
	[ -e "$t" ] || continue
	echo
	echo "== $t =="
	if ! bash "$t"; then
		status=1
	fi
done

exit "$status"
