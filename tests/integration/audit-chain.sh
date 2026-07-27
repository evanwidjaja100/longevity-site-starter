#!/bin/sh
# Real-DB audit-chain integration test: deterministic assertions plus a
# concurrent-writer test proving the hash chain cannot fork.
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)

# 1. Deterministic assertions (append-only, verify, tamper detection, constraint).
docker compose run --rm -v "$ROOT/tests:/tests:ro" wpcli wp eval-file /tests/integration/audit-chain.php --allow-root

# 2. Concurrency: launch parallel writers that each append several events. With
# the unique previous_event_hash constraint plus transactional retries, the
# chain must remain a single linear sequence with no forks.
WRITERS=${LEL_AUDIT_WRITERS:-5}
EVENTS=${LEL_AUDIT_EVENTS_PER_WRITER:-10}

before=$(docker compose run --rm wpcli wp eval 'echo (int) \Longevity\Core\Audit_Log::verify_chain()["checked"];' --allow-root | tr -d '[:space:]')

pids=""
w=0
while [ "$w" -lt "$WRITERS" ]; do
  docker compose run --rm wpcli wp eval "
\$oid = 800000 + $w;
for (\$i = 0; \$i < $EVENTS; \$i++) {
  \Longevity\Core\Audit_Log::record('concurrency_probe', 'system', \$oid, array('w' => $w, 'i' => \$i), 0, 'integration', true);
}
echo 'writer $w done';
" --allow-root &
  pids="$pids $!"
  w=$((w + 1))
done

rc=0
for pid in $pids; do
  wait "$pid" || rc=1
done
[ "$rc" -eq 0 ] || { echo 'ERROR: a concurrent audit writer failed.' >&2; exit 1; }

# 3. Verify the chain is still a single linear, valid sequence.
result=$(docker compose run --rm wpcli wp eval '
$v = \Longevity\Core\Audit_Log::verify_chain();
echo wp_json_encode(array("valid" => $v["valid"], "checked" => $v["checked"], "errors" => $v["errors"]));
' --allow-root)
echo "Post-concurrency verify_chain: $result"

printf '%s' "$result" | grep -q '"valid":true' || { echo 'ERROR: chain invalid after concurrent writes (possible fork).' >&2; exit 1; }

expected=$((before + WRITERS * EVENTS))
after=$(printf '%s' "$result" | sed -E 's/.*"checked":([0-9]+).*/\1/')
[ "$after" -ge "$expected" ] || { echo "ERROR: expected at least $expected events, chain has $after." >&2; exit 1; }

echo 'Audit-chain concurrency integration test passed (single linear chain, no forks).'
