#!/usr/bin/env bash
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git ls-files -z
else
  find . -type f -print0
fi | while IFS= read -r -d '' file; do
  file=${file#./}
  case "$file" in
    MANIFEST.sha256|.env|.env.local|.env.*.local|vendor/*|node_modules/*|reports/*|.phpunit.cache/*|coverage/*|playwright-report/*|test-results/*|build/*|wp-content/uploads/*) continue ;;
    *.log|*.sql|*.sql.gz|*.tgz|*.tar.gz) continue ;;
  esac
  printf '%s\0' "$file"
done | sort -z
