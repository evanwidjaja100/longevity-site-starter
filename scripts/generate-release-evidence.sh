#!/usr/bin/env bash
# Verify and index immutable upstream CI evidence; never convert unavailable work into a pass.
set -euo pipefail
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

REPORTS=${EVIDENCE_REPORTS_DIR:-reports}
REGISTRY=config/release-required-jobs.json
VERSION=${1:-${GITHUB_SHA:-}}
EVENT=${GITHUB_EVENT_NAME:-push}
SHA=${GITHUB_SHA:-}
RUN_ID=${GITHUB_RUN_ID:-}
[[ "$VERSION" != '' && "$SHA" =~ ^[a-f0-9]{40}$ && "$RUN_ID" =~ ^[0-9]+$ ]] || {
  echo 'ERROR: version, GITHUB_SHA, and GITHUB_RUN_ID are mandatory for release evidence.' >&2
  exit 1
}

DIR="$REPORTS/release/$VERSION"
rm -rf "$DIR"
mkdir -p "$DIR"
[[ -d "$REPORTS/upstream" ]] || { echo 'ERROR: downloaded upstream evidence is missing.' >&2; exit 1; }
[[ -f "$REPORTS/ci-job-results.json" ]] || { echo 'ERROR: CI job results are missing.' >&2; exit 1; }

php scripts/verify-release-evidence.php "$REGISTRY" "$REPORTS" "$EVENT" "$SHA" "$RUN_ID" "$DIR/evidence-index.json"
cp -R "$REPORTS/upstream" "$DIR/artifacts"
cp "$REPORTS/ci-job-results.json" "$DIR/ci-job-results.json"
cp "$REGISTRY" "$DIR/release-required-jobs.json"

cat > "$DIR/human-verification-required.md" <<'EOF'
# Human and external verification required

This repository evidence does **not** complete private staging, restore/rollback drills,
production credentials and MFA, HTTPS/HSTS/WAF, SMTP/DNS authentication, monitoring,
branch protection, manual screen-reader/zoom checks, legal/privacy approval, reviewer
credential verification, editorial/medical/product/commercial approval, or launch sign-off.
Those controls remain `unknown_external` until named owners provide dated evidence.
EOF

(cd "$DIR" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum) > "$DIR/SHA256SUMS"
index_sha=$(sha256sum "$DIR/evidence-index.json" | awk '{print $1}')
printf '{"schema_version":1,"result":"pending_github_attestation","subjects":["evidence-index.json","SHA256SUMS"],"evidence_index_sha256":"%s","commit_sha":"%s","workflow_run_id":"%s"}\n' "$index_sha" "$SHA" "$RUN_ID" > "$DIR/provenance-request.json"
# Include the request itself in the final checksum set.
(cd "$DIR" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum) > "$DIR/SHA256SUMS"
(cd "$DIR" && sha256sum -c SHA256SUMS >/dev/null)
printf 'Release evidence written to %s\n' "$DIR"
