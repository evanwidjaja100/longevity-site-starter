#!/bin/sh
set -eu
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
docker compose run --rm -v "$ROOT/tests:/tests:ro" wpcli wp eval-file /tests/integration/pr2-security-contracts.php --allow-root
