#!/bin/sh
# Real-DB invalidation-queue concurrency test (T01-05 / A6).
#
# Two contracts that only a real MySQL server can prove:
#   1. Concurrent enqueues of the same parent collapse to exactly one open
#      row (uniq_open_parent unique key + INSERT ... ON DUPLICATE KEY).
#   2. Two workers running in parallel never claim the same job: the atomic
#      UPDATE claim means the combined processed count equals the number of
#      seeded jobs (never more), and every job reaches a terminal state.
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"

run() { docker compose run --rm wpcli wp eval "$1" --allow-root; }

# Explicit test installation: workers themselves must remain DDL-free.
run "require_once ABSPATH . 'wp-admin/includes/upgrade.php'; \Longevity\Core\Audit_Log::install(); \Longevity\Core\Invalidation_Queue::install(); if (!\Longevity\Core\Audit_Log::ensure_fork_constraint() || !\Longevity\Core\Audit_Log::ensure_idempotency_constraint() || !\Longevity\Core\Invalidation_Queue::ensure_open_uniqueness()) { throw new \RuntimeException('queue integrity schema unavailable'); }" >/dev/null

# --- Part 1: concurrent duplicate enqueue -> exactly one open row ----------
P=990001
run "global \$wpdb; \$t=\Longevity\Core\Invalidation_Queue::table_name(); \$wpdb->query(\$wpdb->prepare(\"DELETE FROM \$t WHERE parent_post_id = %d\", $P));" >/dev/null

pids=""
i=0
while [ "$i" -lt 8 ]; do
	run "\Longevity\Core\Invalidation_Queue::enqueue(array($P), 'qc-dedup', 0);" >/dev/null 2>&1 &
	pids="$pids $!"
	i=$((i + 1))
done
rc=0
for pid in $pids; do
	wait "$pid" || rc=1
done
[ "$rc" -eq 0 ] || { echo 'ERROR: a concurrent enqueue process failed.' >&2; exit 1; }

open=$(run "global \$wpdb; \$t=\Longevity\Core\Invalidation_Queue::table_name(); echo (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM \$t WHERE parent_post_id = %d AND open_marker = 1\", $P));" | tr -d '[:space:]')
[ "$open" = "1" ] || { echo "ERROR: expected exactly 1 open row for parent $P, got '$open'." >&2; exit 1; }
echo "Dedup contract passed: exactly one open row for parent $P under concurrent enqueue."

# Do not let Part 1's open dedup row become a thirteenth worker job.
run "global \$wpdb; \$t=\Longevity\Core\Invalidation_Queue::table_name(); \$wpdb->query(\$wpdb->prepare(\"DELETE FROM \$t WHERE parent_post_id = %d\", $P));" >/dev/null

# --- Part 2: two workers, N distinct jobs, no double claim ------------------
N=12
LOW=990100
HIGH=$((LOW + N - 1))
run "global \$wpdb; \$t=\Longevity\Core\Invalidation_Queue::table_name(); \$wpdb->query(\"DELETE FROM \$t WHERE parent_post_id BETWEEN $LOW AND $HIGH\");" >/dev/null
audit_before=$(run "global \$wpdb; \$t=\Longevity\Core\Audit_Log::table_name(); echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM \$t WHERE event_type='approval_invalidated' AND object_type='post' AND object_id BETWEEN $LOW AND $HIGH\");" | tr -d '[:space:]')
run "\Longevity\Core\Invalidation_Queue::enqueue(range($LOW, $HIGH), 'qc-workers', 0);" >/dev/null

W1=$(mktemp); W2=$(mktemp)
trap 'rm -f "$W1" "$W2"' EXIT
run "echo (int) \Longevity\Core\Invalidation_Queue::process_batch();" > "$W1" 2>/dev/null &
p1=$!
run "echo (int) \Longevity\Core\Invalidation_Queue::process_batch();" > "$W2" 2>/dev/null &
p2=$!
rc=0
wait "$p1" || rc=1
wait "$p2" || rc=1
[ "$rc" -eq 0 ] || { echo 'ERROR: a queue worker process failed.' >&2; exit 1; }

w1=$(tr -dc '0-9' < "$W1"); w1=${w1:-0}
w2=$(tr -dc '0-9' < "$W2"); w2=${w2:-0}
total=$((w1 + w2))
echo "worker A processed $w1, worker B processed $w2 (total $total of $N)."

# process_batch() may also claim unrelated pending fixture jobs. The scoped DB
# assertions below are authoritative for the seeded range.

completed=$(run "global \$wpdb; \$t=\Longevity\Core\Invalidation_Queue::table_name(); echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM \$t WHERE parent_post_id BETWEEN $LOW AND $HIGH AND status='completed'\");" | tr -d '[:space:]')
open2=$(run "global \$wpdb; \$t=\Longevity\Core\Invalidation_Queue::table_name(); echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM \$t WHERE parent_post_id BETWEEN $LOW AND $HIGH AND open_marker=1\");" | tr -d '[:space:]')
audit_after=$(run "global \$wpdb; \$t=\Longevity\Core\Audit_Log::table_name(); echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM \$t WHERE event_type='approval_invalidated' AND object_type='post' AND object_id BETWEEN $LOW AND $HIGH\");" | tr -d '[:space:]')
[ "$completed" = "$N" ] || { echo "ERROR: expected $N completed jobs, got '$completed'." >&2; exit 1; }
[ "$open2" = "0" ] || { echo "ERROR: expected 0 open jobs after processing, got '$open2'." >&2; exit 1; }
[ $((audit_after - audit_before)) -eq "$N" ] || { echo "ERROR: expected exactly $N new durable invalidation events, got $((audit_after - audit_before))." >&2; exit 1; }

# Housekeeping: remove the synthetic rows.
run "global \$wpdb; \$t=\Longevity\Core\Invalidation_Queue::table_name(); \$wpdb->query(\"DELETE FROM \$t WHERE parent_post_id = $P OR parent_post_id BETWEEN $LOW AND $HIGH\");" >/dev/null

echo 'Invalidation-queue concurrency integration test passed (single claim per job, all terminal).'
