#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SITE_URL=${WP_SITE_URL:-http://localhost:8080}

status=$(curl --silent --output /tmp/lel-readiness-anonymous.json --write-out '%{http_code}' "$SITE_URL/wp-json/longevity/v1/system-readiness")
case "$status" in
  401|403) ;;
  *) echo "ERROR: anonymous readiness request returned HTTP $status" >&2; cat /tmp/lel-readiness-anonymous.json >&2; exit 1 ;;
esac

mkdir -p "$ROOT/reports"
docker compose run --rm -v "$ROOT/tests:/tests:ro" wpcli wp eval '
$report = \Longevity\Core\System_Readiness::report();
$required = array("database", "migrations", "scoring_model", "freshness", "operational_counts", "cron_heartbeat", "uploads", "approval_table", "audit_table", "mail_transport", "last_backup", "last_restore_drill");
foreach ($required as $key) { if (!isset($report["checks"][$key]["status"])) { fwrite(STDERR, "Missing readiness check: {$key}\n"); exit(1); } }
foreach (array("mail_transport", "last_backup", "last_restore_drill") as $key) {
  if (!in_array($report["checks"][$key]["status"], array("ok", "unknown_external"), true)) { fwrite(STDERR, "Invalid external status: {$key}\n"); exit(1); }
}
echo wp_json_encode($report), "\n";
' --allow-root > reports/system-readiness.json
python3 -m json.tool reports/system-readiness.json >/dev/null
printf '%s\n' 'Protected system-readiness integration test passed.'
