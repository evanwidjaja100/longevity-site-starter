#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
rm -f /tmp/lel_env_parser_executed
"$ROOT/scripts/validate-env.sh" "$ROOT/.env.ci" >/dev/null
if "$ROOT/scripts/validate-env.sh" "$ROOT/tests/fixtures/invalid.env" >/dev/null 2>&1; then
  echo 'ERROR: invalid environment fixture passed.' >&2
  exit 1
fi
"$ROOT/scripts/validate-env.sh" "$ROOT/tests/fixtures/malicious.env" >/dev/null
if [ -e /tmp/lel_env_parser_executed ]; then
  echo 'ERROR: environment validator executed dotenv content.' >&2
  exit 1
fi
echo 'Environment validation integration tests passed.'
