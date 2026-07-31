#!/bin/sh
# Real-DB contact-idempotency concurrency test (PR-08).
#
# Contracts that only a real MySQL server can prove:
#   1. N parallel reservations of the same request key produce exactly one
#      owner ('reserved'); every other process observes an existing row.
#   2. After completion, every retry resolves to 'completed' with the same
#      aggregate post ID and no second reservation row exists.
#   3. An expired-processing lease is reclaimed by exactly one contender.
set -eu
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"

run() { docker compose run --rm wpcli wp eval "$1" --allow-root; }

KEY_SEED="integration-$(date +%s)"

run "require_once ABSPATH . 'wp-admin/includes/upgrade.php'; \Longevity\Core\Contact_Idempotency::install(); if (!\Longevity\Core\Contact_Idempotency::exists()) { throw new \RuntimeException('contact idempotency table unavailable'); }" >/dev/null

# --- Part 1: N parallel reservations -> exactly one owner -------------------
N=8
OUT_DIR=$(mktemp -d)
trap 'rm -rf "$OUT_DIR"' EXIT

pids=""
i=0
while [ "$i" -lt "$N" ]; do
	run "\$k=\Longevity\Core\Contact_Idempotency::key_hash('$KEY_SEED'); \$r=\Longevity\Core\Contact_Idempotency::reserve(\$k); echo \$r['state'];" > "$OUT_DIR/r$i" 2>/dev/null &
	pids="$pids $!"
	i=$((i + 1))
done
rc=0
for pid in $pids; do
	wait "$pid" || rc=1
done
[ "$rc" -eq 0 ] || { echo 'ERROR: a concurrent reservation process failed.' >&2; exit 1; }

owners=0
others=0
i=0
while [ "$i" -lt "$N" ]; do
	state=$(tr -d '[:space:]' < "$OUT_DIR/r$i")
	case "$state" in
		reserved) owners=$((owners + 1)) ;;
		in_progress|completed) others=$((others + 1)) ;;
		*) echo "ERROR: unexpected reservation state '$state'." >&2; exit 1 ;;
	esac
	i=$((i + 1))
done
[ "$owners" = "1" ] || { echo "ERROR: expected exactly 1 reservation owner, got $owners." >&2; exit 1; }
[ "$others" = "$((N - 1))" ] || { echo "ERROR: expected $((N - 1)) non-owners, got $others." >&2; exit 1; }

rows=$(run "global \$wpdb; \$t=\Longevity\Core\Contact_Idempotency::table_name(); \$k=\Longevity\Core\Contact_Idempotency::key_hash('$KEY_SEED'); echo (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM \$t WHERE request_key_hash = %s\", \$k));" | tr -d '[:space:]')
[ "$rows" = "1" ] || { echo "ERROR: expected exactly 1 reservation row, got '$rows'." >&2; exit 1; }
echo "Reservation contract passed: 1 owner and 1 row across $N parallel requests."

# --- Part 2: completion is terminal and idempotent ---------------------------
run "\$k=\Longevity\Core\Contact_Idempotency::key_hash('$KEY_SEED'); if (!\Longevity\Core\Contact_Idempotency::complete(\$k, 990777)) { throw new \RuntimeException('complete() failed'); }" >/dev/null

pids=""
i=0
while [ "$i" -lt 4 ]; do
	run "\$k=\Longevity\Core\Contact_Idempotency::key_hash('$KEY_SEED'); \$r=\Longevity\Core\Contact_Idempotency::reserve(\$k); echo \$r['state'] . ':' . \$r['post_id'];" > "$OUT_DIR/c$i" 2>/dev/null &
	pids="$pids $!"
	i=$((i + 1))
done
rc=0
for pid in $pids; do
	wait "$pid" || rc=1
done
[ "$rc" -eq 0 ] || { echo 'ERROR: a completed-retry process failed.' >&2; exit 1; }

i=0
while [ "$i" -lt 4 ]; do
	result=$(tr -d '[:space:]' < "$OUT_DIR/c$i")
	[ "$result" = "completed:990777" ] || { echo "ERROR: retry after completion returned '$result'." >&2; exit 1; }
	i=$((i + 1))
done
echo 'Completion contract passed: every retry resolves to the same aggregate.'

# --- Part 3: expired lease -> exactly one reclaimer ---------------------------
KEY2_SEED="${KEY_SEED}-expired"
run "global \$wpdb; \$t=\Longevity\Core\Contact_Idempotency::table_name(); \$k=\Longevity\Core\Contact_Idempotency::key_hash('$KEY2_SEED'); \$r=\Longevity\Core\Contact_Idempotency::reserve(\$k); if ('reserved' !== \$r['state']) { throw new \RuntimeException('seed reservation failed'); } \$wpdb->query(\$wpdb->prepare(\"UPDATE \$t SET lease_expires_at = '2001-01-01 00:00:00' WHERE request_key_hash = %s\", \$k));" >/dev/null

pids=""
i=0
while [ "$i" -lt 4 ]; do
	run "\$k=\Longevity\Core\Contact_Idempotency::key_hash('$KEY2_SEED'); \$r=\Longevity\Core\Contact_Idempotency::reserve(\$k); echo \$r['state'];" > "$OUT_DIR/e$i" 2>/dev/null &
	pids="$pids $!"
	i=$((i + 1))
done
rc=0
for pid in $pids; do
	wait "$pid" || rc=1
done
[ "$rc" -eq 0 ] || { echo 'ERROR: a lease-reclaim process failed.' >&2; exit 1; }

reclaimed=0
i=0
while [ "$i" -lt 4 ]; do
	state=$(tr -d '[:space:]' < "$OUT_DIR/e$i")
	case "$state" in
		reclaimed|resume) reclaimed=$((reclaimed + 1)) ;;
		in_progress) : ;;
		*) echo "ERROR: unexpected reclaim state '$state'." >&2; exit 1 ;;
	esac
	i=$((i + 1))
done
[ "$reclaimed" = "1" ] || { echo "ERROR: expected exactly 1 reclaimer, got $reclaimed." >&2; exit 1; }
echo 'Reclaim contract passed: exactly one contender reclaimed the expired lease.'

# Housekeeping: remove the synthetic rows.
run "global \$wpdb; \$t=\Longevity\Core\Contact_Idempotency::table_name(); foreach (array('$KEY_SEED','$KEY2_SEED') as \$seed) { \$wpdb->query(\$wpdb->prepare(\"DELETE FROM \$t WHERE request_key_hash = %s\", \Longevity\Core\Contact_Idempotency::key_hash(\$seed))); }" >/dev/null

echo 'Contact idempotency concurrency integration test passed (single owner, terminal completion, single reclaimer).'
