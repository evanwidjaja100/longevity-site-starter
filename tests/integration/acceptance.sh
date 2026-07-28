#!/bin/sh
# Real-DB negative acceptance tests. Every assertion parses JSON strictly.
set -eu
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"

WRONG_SOURCE_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
WRONG_ARTIFACT_SHA256=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb

wp_cli() {
  docker compose run --rm wpcli wp "$@"
}

ADMIN_USER=$(wp_cli user list --role=administrator --field=ID --allow-root | sed -n '/^[0-9][0-9]*$/ { p; q; }')
[ -n "$ADMIN_USER" ] || { echo 'ERROR: No administrator user was discovered for acceptance tests.' >&2; exit 1; }
PREFIX=$(wp_cli eval 'global $wpdb; echo $wpdb->prefix;' --allow-root 2>/dev/null)
CURRENT_VERSION=$(wp_cli eval 'echo \Longevity\Core\Migrations::CURRENT_VERSION;' --allow-root 2>/dev/null)
OLD_DATA_VERSION=$(wp_cli option get lel_data_version --allow-root 2>/dev/null || true)
OLD_AUDIT_FAILURES=$(wp_cli option get lel_audit_write_failures --allow-root 2>/dev/null || true)
OLD_QUEUE_FAILURES=$(wp_cli option get lel_invalidation_enqueue_failures --allow-root 2>/dev/null || true)
OLD_FRESHNESS_HEARTBEAT=$(wp_cli option get lel_worker_heartbeat_freshness --allow-root 2>/dev/null || true)
RENAMED_TABLE=

restore_option() {
  name=$1
  value=$2
  if [ -n "$value" ]; then
    wp_cli option update "$name" "$value" --allow-root >/dev/null
  else
    wp_cli option delete "$name" --allow-root >/dev/null 2>&1 || true
  fi
}

restore_all() {
	if [ -n "$RENAMED_TABLE" ]; then
		wp_cli eval "global \$wpdb; \$wpdb->query('RENAME TABLE ${RENAMED_TABLE}_acceptance_disabled TO ${RENAMED_TABLE}');" --allow-root >/dev/null 2>&1 || true
    RENAMED_TABLE=
  fi
  restore_option lel_data_version "$OLD_DATA_VERSION"
  restore_option lel_audit_write_failures "$OLD_AUDIT_FAILURES"
  restore_option lel_invalidation_enqueue_failures "$OLD_QUEUE_FAILURES"
  restore_option lel_worker_heartbeat_freshness "$OLD_FRESHNESS_HEARTBEAT"
}
trap restore_all EXIT INT TERM

run_acceptance() {
  set +e
  ACCEPTANCE_OUT=$(wp_cli longevity acceptance --format=json --user="$ADMIN_USER" --allow-root "$@" 2>/dev/null)
  ACCEPTANCE_RC=$?
  set -e
}

assert_report() {
  expected_status=$1
  expected_check=$2
  expected_check_status=$3
	# shellcheck disable=SC2016
	printf '%s' "$ACCEPTANCE_OUT" | php -r '
	  try{$d=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){exit(1);}
	  if(!is_array($d)||array_is_list($d)||array_keys($d)!==["status","checks"]||$d["status"]!==$argv[1]||!is_array($d["checks"])||!array_is_list($d["checks"])){exit(1);}
	  $found=false;$names=[];
	  foreach($d["checks"] as $c){
	    if(!is_array($c)||array_is_list($c)||!is_string($c["name"]??null)||isset($names[$c["name"]])||!in_array($c["status"]??null,["ok","degraded","unknown_external","blocked","error"],true)||!is_string($c["detail"]??null)){exit(1);}
	    $names[$c["name"]]=true;
	    if($c["name"]===$argv[2]&&$c["status"]===$argv[3]){$found=true;}
	  }
	  exit($found?0:1);
	' "$expected_status" "$expected_check" "$expected_check_status"
}

rename_for_check() {
  RENAMED_TABLE=$1
  wp_cli eval "global \$wpdb; if (false === \$wpdb->query('RENAME TABLE ${RENAMED_TABLE} TO ${RENAMED_TABLE}_acceptance_disabled')) { throw new RuntimeException('table rename failed'); }" --allow-root >/dev/null
}

restore_table() {
  wp_cli eval "global \$wpdb; if (false === \$wpdb->query('RENAME TABLE ${RENAMED_TABLE}_acceptance_disabled TO ${RENAMED_TABLE}')) { throw new RuntimeException('table restore failed'); }" --allow-root >/dev/null
  RENAMED_TABLE=
}

# Missing exact candidate identity must block promotion and exit non-zero.
run_acceptance
[ "$ACCEPTANCE_RC" -ne 0 ] || { echo 'ERROR: missing artifact identity exited zero.' >&2; exit 1; }
assert_report blocked artifact_identity blocked || { echo 'ERROR: missing identity was not structurally blocked.' >&2; exit 1; }

# A well-formed but wrong candidate identity must also exit non-zero. If the
# deployment identity itself is absent, the check is the stricter error state.
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
[ "$ACCEPTANCE_RC" -ne 0 ] || { echo 'ERROR: wrong artifact identity exited zero.' >&2; exit 1; }
# shellcheck disable=SC2016
printf '%s' "$ACCEPTANCE_OUT" | php -r '
  try{$d=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){exit(1);} if(!is_array($d)||($d["status"]??null)!=="blocked"){exit(1);}
  foreach($d["checks"]??[] as $c){if(($c["name"]??null)==="artifact_identity"&&in_array($c["status"]??null,["blocked","error"],true)){exit(0);}} exit(1);
' || { echo 'ERROR: wrong identity did not fail artifact_identity.' >&2; exit 1; }

# Individual worker heartbeats, not one aggregate cron timestamp, are required.
wp_cli option update lel_worker_heartbeat_freshness '2000-01-01T00:00:00Z' --allow-root >/dev/null
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
[ "$ACCEPTANCE_RC" -ne 0 ] || { echo 'ERROR: stale worker heartbeat exited zero.' >&2; exit 1; }
assert_report blocked worker_freshness blocked || { echo 'ERROR: stale worker heartbeat was not structurally reported.' >&2; exit 1; }
restore_option lel_worker_heartbeat_freshness "$OLD_FRESHNESS_HEARTBEAT"

# Migration version mismatch.
wp_cli option update lel_data_version 0 --allow-root >/dev/null
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
assert_report blocked migrations blocked || { echo 'ERROR: broken migration state was not blocked.' >&2; exit 1; }
restore_option lel_data_version "${OLD_DATA_VERSION:-$CURRENT_VERSION}"

# Missing evidence storage also proves absent mail evidence cannot pass.
rename_for_check "${PREFIX}lel_external_evidence"
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
assert_report blocked migrations blocked || { echo 'ERROR: missing table was not blocked.' >&2; exit 1; }
assert_report blocked mail_transport unknown_external || { echo 'ERROR: unavailable mail evidence was not blocked.' >&2; exit 1; }
restore_table

# Missing audit storage/chain.
rename_for_check "${PREFIX}lel_audit_events"
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
assert_report blocked audit_chain blocked || { echo 'ERROR: broken audit state was not blocked.' >&2; exit 1; }
restore_table

# Missing queue table and a recorded queue-write failure both fail closed.
rename_for_check "${PREFIX}lel_invalidation_queue"
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
assert_report blocked invalidation_queue blocked || { echo 'ERROR: missing queue was not blocked.' >&2; exit 1; }
restore_table
wp_cli option update lel_invalidation_enqueue_failures 1 --allow-root >/dev/null
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
assert_report blocked invalidation_queue blocked || { echo 'ERROR: queue write failure was not blocked.' >&2; exit 1; }
restore_option lel_invalidation_enqueue_failures "$OLD_QUEUE_FAILURES"

# Audit durability counters remain promotion-blocking even with an intact chain.
wp_cli option update lel_audit_write_failures 1 --allow-root >/dev/null
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
assert_report blocked audit_write_failures degraded || { echo 'ERROR: audit write failure was not blocked.' >&2; exit 1; }
restore_option lel_audit_write_failures "$OLD_AUDIT_FAILURES"

# The safe code-side probe runs without grades/approvals; contact stays external.
run_acceptance --source-sha="$WRONG_SOURCE_SHA" --artifact-checksum="$WRONG_ARTIFACT_SHA256"
assert_report blocked synthetic_code_workflow ok || { echo 'ERROR: isolated synthetic code workflow failed.' >&2; exit 1; }
assert_report blocked synthetic_contact_delivery unknown_external || { echo 'ERROR: non-isolated contact check did not remain externally blocked.' >&2; exit 1; }

restore_all
trap - EXIT INT TERM
echo 'Acceptance integration negative tests passed.'
