#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
rm -f /tmp/lel_env_parser_executed
# Generate an ephemeral CI env from the tracked non-secret template and validate
# it. Secrets are never committed (see scripts/ci-generate-env.sh).
CI_ENV=$(mktemp)
trap 'rm -f "$CI_ENV"' EXIT
bash "$ROOT/scripts/ci-generate-env.sh" "$CI_ENV" >/dev/null
"$ROOT/scripts/validate-env.sh" "$CI_ENV" >/dev/null
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
