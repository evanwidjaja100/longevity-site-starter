#!/usr/bin/env bash
# Generate release evidence bundle at reports/release/<version>/
set -euo pipefail

VERSION="${1:-unknown}"
DIR="reports/release/$VERSION"
mkdir -p "$DIR"

echo "=== Generating release evidence bundle for $VERSION ==="

# 1. Commit info
git log -1 --format="%H %s%n%ai%n%an <%ae>" > "$DIR/commit.txt"

# 2. Environment info
{
  echo "PHP: $(php -v 2>/dev/null | head -1 || echo 'N/A')"
  echo "Node: $(node -v 2>/dev/null || echo 'N/A')"
  echo "WP: $(docker compose exec -T wordpress wp core version 2>/dev/null || echo 'N/A')"
  echo "Docker Compose: $(docker compose version 2>/dev/null || echo 'N/A')"
  echo "Date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$DIR/environment.txt"

# 3. Validation results
{
  echo "=== PHP Lint ==="
  find wp-content/mu-plugins/longevity-core -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
  echo "=== ShellCheck ==="
  shellcheck scripts/*.sh 2>&1 || true
} > "$DIR/validation.txt" 2>&1 || true

# 4. PHPUnit tests
if [ -f vendor/bin/phpunit ]; then
  vendor/bin/phpunit --log-junit "$DIR/php-tests.xml" 2>&1 | tail -5 || true
elif [ -f tests/php/run-unit-tests.php ]; then
  php tests/php/run-unit-tests.php 2>&1 | tee "$DIR/php-tests.xml" || true
fi

# 5. Integration tests
{
  bash tests/integration/health-endpoint.sh 2>&1 || true
  bash tests/integration/environment-validation.sh 2>&1 || true
} > "$DIR/integration-tests.txt" 2>&1 || true

# 6. Playwright E2E report (if exists)
if [ -d reports/playwright ]; then
  cp -r reports/playwright "$DIR/playwright-report/" 2>/dev/null || true
fi

# 7. Accessibility report
if [ -f reports/playwright/accessibility-results.json ]; then
  cp reports/playwright/accessibility-results.json "$DIR/accessibility-report.json" 2>/dev/null || true
fi

# 8. Visual diff report
if [ -d tests/e2e/snapshots ]; then
  cp -r tests/e2e/snapshots "$DIR/visual-diff-report/" 2>/dev/null || true
fi

# 9. Lighthouse reports
if [ -d reports/lighthouse ]; then
  cp -r reports/lighthouse "$DIR/" 2>/dev/null || true
  for f in "$DIR"/lighthouse/*.json; do
    dir=$(dirname "$f")
    base=$(basename "$f" .json)
    mkdir -p "$DIR/lighthouse-mobile" "$DIR/lighthouse-desktop"
    if echo "$base" | grep -qi 'desktop'; then
      mv "$f" "$DIR/lighthouse-desktop/" 2>/dev/null || true
    else
      mv "$f" "$DIR/lighthouse-mobile/" 2>/dev/null || true
    fi
  done
  rmdir "$DIR/lighthouse" 2>/dev/null || true
fi

# 10. Route crawl CSV
{
  echo "route,status,time"
  for url in \
    "/" "/start-here/" "/topics/" "/reviews/" "/editorial-policy/" \
    "/testing-methodology/" "/medical-disclaimer/" "/corrections/" \
    "/about/" "/affiliate-disclosure/" "/category/evidence-literacy/" \
    "/category/sleep/" "/category/movement/";
  do
    code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080$url" 2>/dev/null || echo '000')
    echo "$url,$code,$(date -u +%H:%M:%S)"
  done
} > "$DIR/route-crawl.csv" 2>&1 || true

# 11. Security scan placeholder
echo "Security scan results: see docs/operations/security-checklist.md" > "$DIR/security-scan/README.md" 2>/dev/null || true

# 12. Placeholder manual QA
cat > "$DIR/manual-qa.md" << 'MANUAL'
# Manual QA — $VERSION

## Browser checks
- [ ] Chromium: all critical paths pass
- [ ] Firefox: critical paths pass
- [ ] Safari: critical paths pass

## Accessibility
- [ ] Keyboard-only navigation verified
- [ ] VoiceOver + Safari tested
- [ ] NVDA + Firefox/Chrome tested
- [ ] 200% and 400% zoom no overflow

## Content
- [ ] No draft pages linked from navigation
- [ ] Missing meta or broken sections documented
MANUAL

# 13. Backup/restore evidence placeholder
cat > "$DIR/backup-restore-evidence.md" << 'BACKUP'
# Backup/Restore — $VERSION

- Database backup: [datetime/duration]
- Files backup: [datetime/duration]
- Restore test: [pass/fail with notes]
BACKUP

# 14. Known limitations
cat > "$DIR/known-limitations.md" << 'LIMITATIONS'
# Known Limitations — $VERSION

List known deviations from the plan, deferred items, or acceptable risks.
LIMITATIONS

echo "=== Release evidence bundle written to $DIR ==="
ls -la "$DIR/"
