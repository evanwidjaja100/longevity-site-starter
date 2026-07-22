#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

missing=0
for lock in composer.lock package-lock.json; do
  if [ ! -f "$lock" ]; then
    echo "ERROR: required lockfile is missing: $lock" >&2
    missing=1
  fi
done
[ "$missing" -eq 0 ] || exit 1

command -v composer >/dev/null 2>&1 || { echo 'ERROR: Composer is required.' >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { echo 'ERROR: npm is required.' >&2; exit 1; }

composer validate --strict --no-check-publish
composer install --dry-run --no-interaction --prefer-dist --no-progress
npm ci --ignore-scripts --dry-run --no-audit --no-fund

if grep -Eq '"type"[[:space:]]*:[[:space:]]*"(path|vcs|package)"' composer.json; then
  echo 'ERROR: unsupported Composer repository source detected.' >&2
  exit 1
fi
if grep -RInE 'composer install|npm (install|ci)' .github/workflows 2>/dev/null | grep -E 'composer install( |$)|npm ci' >/dev/null; then
  :
else
  echo 'ERROR: CI does not contain locked Composer and npm install commands.' >&2
  exit 1
fi

echo 'Dependency state is locked and internally consistent.'
