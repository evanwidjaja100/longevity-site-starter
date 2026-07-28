#!/bin/sh
# Real-DB freshness cycle-lock test (T01-05 / A6).
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"

TOKEN=$$
READY="$ROOT/wp-content/.lel-freshness-lock-ready-$TOKEN"
RELEASE="$ROOT/wp-content/.lel-freshness-lock-release-$TOKEN"
HOLD=$(mktemp)
cleanup() {
	: > "$RELEASE"
	rm -f "$READY" "$RELEASE" "$HOLD"
}
trap cleanup EXIT INT TERM

holder='
$name = \Longevity\Core\Advisory_Lock::namespaced_name( "freshness_cycle" );
$state = \Longevity\Core\Advisory_Lock::acquire( $name, 0 );
echo $state;
if ( \Longevity\Core\Advisory_Lock::ACQUIRED !== $state ) { exit( 2 ); }
$ready = WP_CONTENT_DIR . "/.lel-freshness-lock-ready-'"$TOKEN"'";
$release = WP_CONTENT_DIR . "/.lel-freshness-lock-release-'"$TOKEN"'";
file_put_contents( $ready, "ready" );
$deadline = microtime( true ) + 60;
while ( ! file_exists( $release ) && microtime( true ) < $deadline ) { usleep( 100000 ); }
if ( ! file_exists( $release ) ) { exit( 3 ); }
if ( \Longevity\Core\Advisory_Lock::RELEASED !== \Longevity\Core\Advisory_Lock::release( $name ) ) { exit( 4 ); }
'
docker compose run --rm wpcli wp eval "$holder" --allow-root > "$HOLD" 2>/dev/null &
ph=$!

i=0
while [ ! -f "$READY" ]; do
	i=$((i + 1))
	[ "$i" -lt 600 ] || { echo 'ERROR: freshness holder did not reach ready barrier.' >&2; exit 1; }
	sleep 0.1
done

status=$(docker compose run --rm wpcli wp eval '$r = \Longevity\Core\Freshness::run(); echo isset( $r["status"] ) ? $r["status"] : "missing";' --allow-root 2>/dev/null | tr -d '[:space:]')
: > "$RELEASE"
wait "$ph" || { echo 'ERROR: freshness lock holder process failed.' >&2; exit 1; }

held=$(tr -d '[:space:]' < "$HOLD")
[ "$held" = "acquired" ] || { echo "ERROR: holder did not acquire the lock (got '$held')." >&2; exit 1; }
[ "$status" = "locked" ] || { echo "ERROR: freshness cycle did not skip under contention (status='$status')." >&2; exit 1; }

echo 'Freshness cycle-lock integration test passed (deterministic ready/release barrier).'
