#!/usr/bin/env bash
# Prove Psalm detects a controlled taint and enforce time-bounded suppressions.
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

scope=${SAST_SUPPRESSION_SCOPE:-}
if [[ -n "$scope" ]]; then
  sources=("$scope")
else
  sources=(wp-content/mu-plugins/longevity-core.php wp-content/mu-plugins/longevity-core wp-content/themes/longevity-starter scripts)
fi

today=$(date -u +%F)
while IFS= read -r match; do
  if ! { printf '%s\n' "$match" | grep -Eq 'reason="[^"]+"' &&
    printf '%s\n' "$match" | grep -Eq 'owner="@[A-Za-z0-9_.-]+"' &&
    printf '%s\n' "$match" | grep -Eq 'expires="[0-9]{4}-[0-9]{2}-[0-9]{2}"'; }; then
    echo "FAIL: Psalm suppression needs reason, @owner, and YYYY-MM-DD expiry: $match" >&2
    exit 1
  fi
  expiry=$(printf '%s\n' "$match" | sed -n 's/.*expires="\([0-9-]*\)".*/\1/p')
  [[ "$expiry" > "$today" || "$expiry" == "$today" ]] || {
    echo "FAIL: expired Psalm suppression: $match" >&2
    exit 1
  }
done < <(grep -RInE --include='*.php' '@psalm-suppress' "${sources[@]}" 2>/dev/null || true)

if grep -Eq '<issueHandlers([ >])' psalm.xml; then
  echo 'FAIL: blanket Psalm issueHandlers are prohibited; use a time-bounded inline suppression.' >&2
  exit 1
fi
[[ "${1:-}" != '--suppressions-only' ]] || { echo 'PASS: SAST suppression policy.'; exit 0; }

PSALM=./vendor/bin/psalm
[[ -f "$PSALM" ]] || { echo 'FAIL: Psalm is not installed.' >&2; exit 1; }
output=$($PSALM -c tests/fixtures/sast/psalm-fixture.xml --taint-analysis --output-format=json --no-progress --no-cache 2>/dev/null || true)
printf '%s' "$output" | grep -q '"type": *"TaintedHtml"' || {
  echo 'FAIL: Psalm did not flag TaintedHtml in the controlled fixture.' >&2
  printf '%s\n' "$output" >&2
  exit 1
}
report=${SAST_DETECTION_REPORT:-reports/php-sast/detection-proof.json}
mkdir -p "$(dirname "$report")"
printf '{"schema_version":1,"result":"success","scanner":"Psalm","expected_issue":"TaintedHtml","fixture":"tests/fixtures/sast/taint-fixture.php","detected":true,"suppression_policy":"owner-reason-expiry"}\n' > "$report"
echo 'PASS: SAST detection and suppression policy.'
