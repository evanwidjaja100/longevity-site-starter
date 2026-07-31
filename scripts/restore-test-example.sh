#!/bin/sh
set -eu
[ "${WP_ENVIRONMENT_TYPE:-local}" != "production" ] || { echo 'ERROR: This reference restore script refuses production.' >&2; exit 1; }
[ "${CONFIRM_DESTRUCTIVE_RESTORE:-}" = "RESTORE-TEST" ] || { echo 'ERROR: Set CONFIRM_DESTRUCTIVE_RESTORE=RESTORE-TEST.' >&2; exit 1; }
SQL_FILE=${1:-}
if [ -z "$SQL_FILE" ] || [ ! -r "$SQL_FILE" ]; then
  echo 'Usage: restore-test-example.sh path/to/database.sql' >&2
  exit 1
fi
echo 'This replaces the non-production database. Verify the target environment before continuing.'
docker compose run --rm -T wpcli wp db import - --allow-root < "$SQL_FILE"
docker compose run --rm wpcli wp cache flush --allow-root
./scripts/smoke-test.sh
echo 'Reference restore test completed. Record the drill, duration, owner, and validation evidence.'
