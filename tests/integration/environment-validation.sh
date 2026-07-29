#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
rm -f /tmp/lel_env_parser_executed
# Generate an ephemeral CI env from the tracked non-secret template and validate
# it. Secrets are never committed (see scripts/ci-generate-env.sh).
CI_ENV=$(mktemp)
CSP_ENV=$(mktemp)
trap 'rm -f "$CI_ENV" "$CSP_ENV"' EXIT
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

# CSP mode contract: retired key rejected, enum enforced, required in production.
run_validate() {
  env -u LEL_CSP_MODE -u LEL_CSP_ENFORCE "$ROOT/scripts/validate-env.sh" "$1"
}
grep -v '^LEL_CSP_MODE=' "$CI_ENV" > "$CSP_ENV"
printf 'LEL_CSP_ENFORCE=1\n' >> "$CSP_ENV"
if run_validate "$CSP_ENV" >/dev/null 2>&1; then
  echo 'ERROR: retired LEL_CSP_ENFORCE key passed validation.' >&2
  exit 1
fi
grep -v '^LEL_CSP_MODE=' "$CI_ENV" > "$CSP_ENV"
printf 'LEL_CSP_MODE=enforced\n' >> "$CSP_ENV"
if run_validate "$CSP_ENV" >/dev/null 2>&1; then
  echo 'ERROR: unrecognized LEL_CSP_MODE value passed validation.' >&2
  exit 1
fi
grep -v '^LEL_CSP_MODE=' "$CI_ENV" > "$CSP_ENV"
{
  printf 'WP_SITE_URL=https://longevity.example.net\n'
  printf 'WP_ENVIRONMENT_TYPE=production\n'
  printf 'FORCE_SSL_ADMIN=true\n'
  printf 'DISALLOW_FILE_MODS=true\n'
} >> "$CSP_ENV"
if run_validate "$CSP_ENV" >/dev/null 2>&1; then
  echo 'ERROR: production environment without LEL_CSP_MODE passed validation.' >&2
  exit 1
fi
printf 'LEL_CSP_MODE=report-only\n' >> "$CSP_ENV"
run_validate "$CSP_ENV" >/dev/null
printf 'LEL_CSP_MODE=enforce\n' >> "$CSP_ENV"
run_validate "$CSP_ENV" >/dev/null
echo 'Environment validation integration tests passed.'
