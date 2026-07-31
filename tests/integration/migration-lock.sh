#!/bin/sh
# Real-DB advisory-lock mutual-exclusion test (T01-01 / A1-A2).
# Single-quoted php/awk/jq snippets below are intentional (no shell expansion).
# shellcheck disable=SC2016
set -eu
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"

TOKEN=$$
READY="$ROOT/wp-content/.lel-migration-lock-ready-$TOKEN"
RELEASE="$ROOT/wp-content/.lel-migration-lock-release-$TOKEN"
HOLDER_OUT=$(mktemp)
CONTENDER_OUT=$(mktemp)
cleanup() {
	: > "$RELEASE"
	rm -f "$READY" "$RELEASE" "$HOLDER_OUT" "$CONTENDER_OUT"
}
trap cleanup EXIT INT TERM

holder='
$name = \Longevity\Core\Advisory_Lock::namespaced_name( "migration" );
$state = \Longevity\Core\Advisory_Lock::acquire( $name, 0 );
echo $state;
if ( \Longevity\Core\Advisory_Lock::ACQUIRED !== $state ) { exit( 2 ); }
$ready = WP_CONTENT_DIR . "/.lel-migration-lock-ready-'"$TOKEN"'";
$release = WP_CONTENT_DIR . "/.lel-migration-lock-release-'"$TOKEN"'";
file_put_contents( $ready, "ready" );
$deadline = microtime( true ) + 60;
while ( ! file_exists( $release ) && microtime( true ) < $deadline ) { usleep( 100000 ); }
if ( ! file_exists( $release ) ) { exit( 3 ); }
if ( \Longevity\Core\Advisory_Lock::RELEASED !== \Longevity\Core\Advisory_Lock::release( $name ) ) { exit( 4 ); }
'

docker compose run --rm wpcli wp eval "$holder" --allow-root > "$HOLDER_OUT" 2>/dev/null &
ph=$!

# Deterministic ready barrier: do not start the contender until the holder owns the lock.
i=0
while [ ! -f "$READY" ]; do
	i=$((i + 1))
	[ "$i" -lt 600 ] || { echo 'ERROR: holder did not reach ready barrier.' >&2; exit 1; }
	sleep 0.1
done

contender='$name = \Longevity\Core\Advisory_Lock::namespaced_name( "migration" ); echo \Longevity\Core\Advisory_Lock::acquire( $name, 0 );'
docker compose run --rm wpcli wp eval "$contender" --allow-root > "$CONTENDER_OUT" 2>/dev/null
: > "$RELEASE"
wait "$ph" || { echo 'ERROR: advisory-lock holder process failed.' >&2; exit 1; }

held=$(tr -d '[:space:]' < "$HOLDER_OUT")
contended=$(tr -d '[:space:]' < "$CONTENDER_OUT")
[ "$held" = "acquired" ] || { echo "ERROR: holder state was '$held'." >&2; exit 1; }
[ "$contended" = "contended" ] || { echo "ERROR: contender state was '$contended'." >&2; exit 1; }

echo 'Advisory-lock mutual-exclusion integration test passed (deterministic holder/contender barrier).'
