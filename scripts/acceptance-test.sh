#!/bin/sh
# Authenticated pre-launch acceptance sweep.
#
# Runs `wp longevity acceptance` as an administrator and surfaces its result.
# The command exits non-zero unless every check is exactly ok. Works both on a managed host where
# `wp` is on PATH and inside the project's Docker toolchain.
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

# Resolve a WP-CLI runner: an explicit override, a `wp` on PATH, or the
# project's wpcli container.
if [ -n "${WP_CLI_RUNNER:-}" ]; then
  RUNNER="$WP_CLI_RUNNER"
elif command -v wp >/dev/null 2>&1; then
  RUNNER="wp"
elif command -v docker >/dev/null 2>&1; then
  RUNNER="docker compose run --rm wpcli wp"
else
  echo 'ERROR: no WP-CLI runner found (set WP_CLI_RUNNER, install wp, or provide docker).' >&2
  exit 1
fi

# Resolve an administrator user for the authenticated command.
ADMIN_USER=${WP_ADMIN_USER:-}
if [ -z "$ADMIN_USER" ] && [ -f "$ROOT/.env" ]; then
  ADMIN_USER=$(sed -n 's/^WP_ADMIN_USER=//p' "$ROOT/.env" | tail -n1)
  ADMIN_USER=${ADMIN_USER#\"}
  ADMIN_USER=${ADMIN_USER%\"}
fi
[ -n "$ADMIN_USER" ] || { echo 'ERROR: WP_ADMIN_USER is required to run the authenticated acceptance sweep.' >&2; exit 1; }

SOURCE_SHA=${LEL_ACCEPTANCE_SOURCE_SHA:-}
ARTIFACT_SHA256=${LEL_ACCEPTANCE_ARTIFACT_SHA256:-}
[ -n "$SOURCE_SHA" ] || { echo 'ERROR: LEL_ACCEPTANCE_SOURCE_SHA is required.' >&2; exit 1; }
[ -n "$ARTIFACT_SHA256" ] || { echo 'ERROR: LEL_ACCEPTANCE_ARTIFACT_SHA256 is required.' >&2; exit 1; }

# shellcheck disable=SC2086
set +e
OUT=$($RUNNER longevity acceptance --format=json --source-sha="$SOURCE_SHA" --artifact-checksum="$ARTIFACT_SHA256" --user="$ADMIN_USER" --allow-root)
RC=$?
set -e
printf '%s\n' "$OUT"

printf '%s' "$OUT" | php -r 'try{$d=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){exit(1);} if(!is_array($d)||array_is_list($d)||array_keys($d)!==["status","checks"]||$d["status"]!=="ok"||!is_array($d["checks"])||!array_is_list($d["checks"])){exit(1);} $names=[]; foreach($d["checks"] as $c){if(!is_array($c)||array_is_list($c)||!is_string($c["name"]??null)||isset($names[$c["name"]])||($c["status"]??null)!=="ok"||!is_string($c["detail"]??null)){exit(1);} $names[$c["name"]]=true;}'
[ "$RC" -eq 0 ] || { echo "ERROR: acceptance exited $RC." >&2; exit "$RC"; }
