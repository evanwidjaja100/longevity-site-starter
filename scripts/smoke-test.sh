#!/bin/sh
set -eu
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
SITE_URL=${WP_SITE_URL:-}
if [ -z "$SITE_URL" ] && [ -f "$ROOT/.env" ]; then
  SITE_URL=$(sed -n 's/^WP_SITE_URL=//p' "$ROOT/.env" | tail -n1)
fi
[ -n "$SITE_URL" ] || { echo 'ERROR: WP_SITE_URL is required.' >&2; exit 1; }

fetch() {
  url=$1
  output=$2
  status=$(curl --fail --silent --show-error --location --output "$output" --write-out '%{http_code}' "$url")
  case "$status" in 200|301|302) : ;; *) echo "ERROR: $url returned $status" >&2; exit 1;; esac
}

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT INT TERM
fetch "$SITE_URL/" "$tmp/home.html"
fetch "$SITE_URL/wp-json/longevity/v1/health" "$tmp/health.json"
python3 -m json.tool "$tmp/health.json" >/dev/null
grep -q 'Longevity Evidence Lab\|longevity-site-header' "$tmp/home.html" || { echo 'ERROR: Homepage did not contain expected theme output.' >&2; exit 1; }
grep -q '"status":"ok"\|"status": "ok"' "$tmp/health.json" || { echo 'ERROR: Health response was not healthy.' >&2; exit 1; }
! grep -Eqi '(<b>(Fatal error|Warning|Notice)</b>:|PHP (Fatal error|Warning|Notice):|Uncaught [A-Za-z]+:)' "$tmp/home.html" || { echo 'ERROR: PHP diagnostics appeared in public output.' >&2; exit 1; }
echo 'Smoke test passed.'
