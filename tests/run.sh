#!/usr/bin/env bash
# Unit tests: no database, no network. Core is read from SHOPCLASS_CORE, or ../shopclass,
# and needs its composer packages installed.
set -u
cd "$(dirname "$0")/.."
fail=0
for t in tests/*.php; do
    echo "── $t"
    out=$(php "$t")
    code=$?
    printf '%s\n' "$out" | tail -3
    [ "$code" -eq 0 ] || fail=1
    if ! printf '%s\n' "$out" | grep -q '^RESULT: '; then
        echo "no RESULT line"
        fail=1
    fi
done
if command -v node >/dev/null; then
    echo "── tests/admin-check.mjs"
    node tests/admin-check.mjs || fail=1
fi
shopt -s nullglob
for f in index.php src/*.php src/admin/*.php admin/*.php; do
    php -l "$f" >/dev/null || fail=1
done
exit "$fail"
