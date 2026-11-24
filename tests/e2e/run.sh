#!/bin/sh
# Real-install checks: builds a throwaway WordPress + WooCommerce in Docker,
# installs the plugin from this checkout, and runs every scenario under both
# HPOS and legacy order storage. Kunaki's API is faked (kunaki-mock.php), so
# nothing contacts the real service. See README.md.
set -eu
cd "$(dirname "$0")"

PROJECT=kunakistaging
CLI="${PROJECT}-cli-1"

cleanup() { docker compose -p "$PROJECT" down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

rm -f out.txt err-full.txt
cleanup # Always start fresh: the checks end by uninstalling the plugin.
docker compose -p "$PROJECT" up -d

echo "Waiting for WordPress files..."
i=0
until docker exec "$CLI" test -f /var/www/html/wp-config.php 2>/dev/null; do
	i=$((i + 1))
	if [ "$i" -gt 60 ]; then echo "Timed out waiting for WordPress." >&2; exit 1; fi
	sleep 2
done
i=0
until docker exec "$CLI" wp db check >/dev/null 2>&1; do
	i=$((i + 1))
	if [ "$i" -gt 60 ]; then echo "Timed out waiting for the database." >&2; exit 1; fi
	sleep 2
done

for f in kunaki-mock.php setup.sh s.php; do docker cp "$f" "$CLI:/tmp/$f"; done
docker exec -i "$CLI" sh /tmp/setup.sh

status=0
python3 run.py > out.txt 2>&1 || status=$?

pass=$(grep -c '^PASS' out.txt || true)
fail=$(grep -c '^FAIL' out.txt || true)
fatals=$(grep -o 'fatals: [0-9]*' out.txt | tail -1 | grep -o '[0-9]*$' || true)
# WP-CLI writes PHP fatals from the scenarios to stderr, not to debug.log.
cli_fatals=$(grep -c 'Fatal error' err-full.txt 2>/dev/null || true)
echo "PASS: $pass  FAIL: $fail  PHP fatals: ${fatals:-?} (web), ${cli_fatals:-0} (WP-CLI)"
grep '^FAIL' out.txt || true
echo "Full output: tests/e2e/out.txt (stderr: tests/e2e/err-full.txt)"

# A crash stops the checks early without printing FAIL, so count it too.
ok=1
if [ "$status" -ne 0 ]; then echo "run.py exited with status $status." >&2; ok=0; fi
if [ "$pass" -eq 0 ]; then echo "No checks passed." >&2; ok=0; fi
if [ -z "$fatals" ]; then echo "The run didn't finish (no fatals count)." >&2; ok=0; fi
if [ "${fatals:-0}" -ne 0 ] || [ "${cli_fatals:-0}" -ne 0 ]; then echo "PHP fatal errors were logged." >&2; ok=0; fi
[ "$fail" -eq 0 ] && [ "$ok" -eq 1 ]
