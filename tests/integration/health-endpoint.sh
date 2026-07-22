#!/bin/sh
set -eu
SITE_URL=${WP_SITE_URL:-http://localhost:8080}
body=$(curl --fail --silent --show-error "$SITE_URL/wp-json/longevity/v1/health")
printf '%s' "$body" | python3 -m json.tool >/dev/null
printf '%s' "$body" | grep -q '"status":"ok"\|"status": "ok"'
echo 'Health endpoint integration test passed.'
