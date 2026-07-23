#!/usr/bin/env bash
# Generate a non-secret release evidence bundle without fabricating unavailable checks.
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
mkdir -p reports
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    VERSION=$(git rev-parse --short HEAD)
  else
    VERSION="reconstructed-$(date -u +%Y%m%dT%H%M%SZ)"
  fi
fi
DIR="reports/release/$VERSION"
mkdir -p "$DIR" "$DIR/logs"

record_command() {
  local name=$1
  shift
  local log="$DIR/logs/$name.log"
  if "$@" >"$log" 2>&1; then
    printf '%s\tPASS\t%s\n' "$name" "$*" >> "$DIR/verification.tsv"
  else
    local status=$?
    printf '%s\tFAIL(%s)\t%s\n' "$name" "$status" "$*" >> "$DIR/verification.tsv"
  fi
}

printf 'check\tresult\tcommand\n' > "$DIR/verification.tsv"

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git log -1 --format='%H%n%s%n%aI%n%an <%ae>' > "$DIR/commit.txt"
  git status --short > "$DIR/working-tree.txt"
  tree_hash=$(git rev-parse HEAD^{tree})
else
  cat > "$DIR/commit.txt" <<'TXT'
UNAVAILABLE: this artifact was generated from a reconstructed Repomix snapshot, not a Git checkout.
TXT
  tree_hash="UNAVAILABLE"
fi

{
  printf 'commit_sha=%s\n' "${GITHUB_SHA:-$(git rev-parse HEAD 2>/dev/null || echo UNAVAILABLE)}"
  printf 'tree_hash=%s\n' "$tree_hash"
  printf 'workflow_run_id=%s\n' "${GITHUB_RUN_ID:-UNAVAILABLE}"
  for file in composer.lock package-lock.json MANIFEST.sha256; do
    key=$(printf '%s' "$file" | tr '.-' '__')
    if [ -f "$file" ]; then
      printf '%s_sha256=%s\n' "$key" "$(sha256sum "$file" | awk '{print $1}')"
    else
      printf '%s_sha256=UNAVAILABLE\n' "$key"
    fi
  done
} > "$DIR/release-metadata.txt"

{
  echo "Generated (UTC): $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "PHP: $(php -v 2>/dev/null | head -1 || echo UNAVAILABLE)"
  echo "Composer: $(composer --version 2>/dev/null || echo UNAVAILABLE)"
  echo "Node: $(node -v 2>/dev/null || echo UNAVAILABLE)"
  echo "npm: $(npm -v 2>/dev/null || echo UNAVAILABLE)"
  echo "Docker: $(docker --version 2>/dev/null || echo UNAVAILABLE)"
  echo "Docker Compose: $(docker compose version 2>/dev/null || echo UNAVAILABLE)"
} > "$DIR/environment.txt"

if [ "${RELEASE_EVIDENCE_AGGREGATE:-0}" = 1 ]; then
  printf '%s\tPASS\tdownloaded upstream CI artifacts\n' upstream-artifacts >> "$DIR/verification.tsv"
else
  record_command php-syntax bash -c "find wp-content tests/php scripts -type f -name '*.php' -print0 | xargs -0 -n1 php -l"
  record_command fallback-security php tests/php/run-unit-tests.php
  record_command test-discovery php scripts/verify-test-discovery.php
  record_command manifest bash scripts/verify-manifest.sh
  record_command dependency-state bash scripts/verify-dependency-state.sh

  if command -v npm >/dev/null 2>&1 && [ -d node_modules ]; then
    record_command frontend-lint npm run lint
    record_command npm-audit npm audit --audit-level=high
  else
    printf '%s\tUNAVAILABLE\t%s\n' frontend-lint 'node_modules is absent' >> "$DIR/verification.tsv"
    printf '%s\tUNAVAILABLE\t%s\n' npm-audit 'node_modules is absent' >> "$DIR/verification.tsv"
  fi
fi

for source in reports/playwright reports/playwright-artifacts reports/lighthouse reports/system-readiness.json reports/sbom.spdx.json; do
  if [ -e "$source" ]; then
    cp -R "$source" "$DIR/" 2>/dev/null || true
  fi
done

cat > "$DIR/human-verification-required.md" <<'EOF_HUMAN'
# Human and external verification required

The following controls must remain `unknown_external` until an authorized owner supplies evidence:

- private staging deployment and migration rehearsal;
- backup restore drill and rollback rehearsal;
- MFA, WAF/CDN, HTTPS/HSTS, secure cookies, and production cron;
- SMTP plus SPF/DKIM/DMARC;
- uptime, error, and centralized-log monitoring;
- GitHub branch protection and required-check configuration;
- manual accessibility checks at keyboard-only, screen reader, 200%, and 400% zoom;
- legal/privacy approval;
- real reviewer credential verification;
- real medical, product-testing, commercial, and publication approvals;
- final launch go/no-go decision.
EOF_HUMAN

has_failures=0
# Event-specific mandatory job sets: dependency-review only runs on pull_request.
EVENT_TYPE="${GITHUB_EVENT_NAME:-push}"
base_jobs='clean-build quality-php quality-frontend content-and-config wordpress-integration playwright-chromium playwright-cross-browser visual-linux lighthouse-mobile lighthouse-desktop codeql security-supply-chain'
base_artifacts='clean-build-evidence php-quality-evidence frontend-quality-evidence content-config-evidence wordpress-integration-evidence playwright-chromium-evidence playwright-cross-browser-evidence visual-linux-evidence lighthouse-evidence lighthouse-desktop-evidence codeql-evidence supply-chain-evidence'
if [ "$EVENT_TYPE" = "pull_request" ]; then
  mandatory_jobs="$base_jobs dependency-review"
  mandatory_artifacts="$base_artifacts dependency-review-evidence"
else
  mandatory_jobs="$base_jobs"
  mandatory_artifacts="$base_artifacts"
fi
if [ -f reports/ci-job-results.json ]; then
  cp reports/ci-job-results.json "$DIR/ci-job-results.json"
else
  printf 'ERROR: mandatory CI job results are missing.\n' >&2
  has_failures=1
fi

if [ -d reports/upstream ]; then
  cp -R reports/upstream "$DIR/upstream-artifacts"
fi

for job in $mandatory_jobs; do
  if ! grep -Eq "\"${job}\"[[:space:]]*:[[:space:]]*\"success\"" reports/ci-job-results.json 2>/dev/null; then
    printf 'ERROR: mandatory CI job is not a success or its result is missing: %s\n' "$job" >&2
    has_failures=1
  fi
done
# Check for failure/cancelled only among mandatory jobs; skipped is acceptable for conditionally-excluded jobs.
for job in $mandatory_jobs; do
  if grep -Eq "\"${job}\"[[:space:]]*:[[:space:]]*\"(failure|cancelled)\"" reports/ci-job-results.json 2>/dev/null; then
    printf 'ERROR: mandatory CI job failed or was cancelled: %s\n' "$job" >&2
    has_failures=1
  fi
done

if [ ! -d reports/upstream ]; then
  printf 'ERROR: downloaded upstream evidence directory is missing.\n' >&2
  has_failures=1
else
  for artifact in $mandatory_artifacts; do
    if ! find "reports/upstream/$artifact" -type f -print -quit 2>/dev/null | grep -q .; then
      printf 'ERROR: mandatory evidence artifact is missing or empty: %s\n' "$artifact" >&2
      has_failures=1
    fi
  done
fi

if grep -Eq '\tFAIL' "$DIR/verification.tsv"; then
  printf 'ERROR: One or more verification checks failed in evidence generation.\n' >&2
  has_failures=1
fi

{
  printf '# Production Readiness v2 evidence — %s\n\n' "$VERSION"
  printf '%s\n' 'This bundle records checks that executed in the current environment. A `FAIL` or `UNAVAILABLE` result is not a pass. See `verification.tsv`, individual logs, and `human-verification-required.md`.'
} > "$DIR/README.md"

printf 'Release evidence written to %s\n' "$DIR"

find "$DIR" -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > "$DIR/SHA256SUMS"

if [ "$has_failures" -ne 0 ]; then
  printf 'Release evidence aggregate FAILED.\n' >&2
  exit 1
fi

