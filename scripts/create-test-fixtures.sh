#!/bin/sh
set -eu

if [ "${WP_ENVIRONMENT_TYPE:-}" = "production" ]; then
  echo "ERROR: Synthetic fixtures must never run in production." >&2
  exit 1
fi

wp eval-file /scripts/create-test-fixtures.php --user="$WP_ADMIN_USER" --allow-root
