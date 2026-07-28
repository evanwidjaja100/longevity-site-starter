#!/usr/bin/env bash
# Manifest verification contract tests (PR-00).
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lel-manifest-test.XXXXXXXX")
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/repo/scripts"
cp "$ROOT/scripts/release-file-list.sh" "$ROOT/scripts/regenerate-manifest.sh" \
   "$ROOT/scripts/verify-manifest.sh" "$ROOT/scripts/regenerate-manifest.php" "$TMP/repo/scripts/"
cd "$TMP/repo"
git init -q
git config user.name Test
git config user.email test@example.invalid

printf '<?php // runtime\n' > runtime.php
: > empty-file.txt
printf 'BIN\x00\x01\x02\xff\xfe\n\x00tail' > binary-file.bin
git add .
git commit -qm initial

# 1. Canonical regeneration covers empty and binary tracked files; verification passes.
bash scripts/regenerate-manifest.sh >/dev/null
git add MANIFEST.sha256 && git commit -qm manifest
grep -q ' \*\./empty-file.txt$' MANIFEST.sha256 || { echo 'FAIL: empty tracked file missing from manifest' >&2; exit 1; }
grep -q ' \*\./binary-file.bin$' MANIFEST.sha256 || { echo 'FAIL: binary tracked file missing from manifest' >&2; exit 1; }
EXPECTED_BIN=$(git show :binary-file.bin | sha256sum | awk '{print $1}')
grep -q "^${EXPECTED_BIN} \*\./binary-file.bin$" MANIFEST.sha256 || { echo 'FAIL: binary file hash is not byte-safe' >&2; exit 1; }
bash scripts/verify-manifest.sh >/dev/null || { echo 'FAIL: valid manifest rejected' >&2; exit 1; }

# 2. Missing manifest entry (stale manifest after adding a file) is rejected.
printf '<?php // new\n' > added-later.php
git add added-later.php && git commit -qm add-file
if bash scripts/verify-manifest.sh >/dev/null 2>&1; then echo 'FAIL: manifest missing an entry accepted' >&2; exit 1; fi
bash scripts/regenerate-manifest.sh >/dev/null && git add MANIFEST.sha256 && git commit -qm manifest2

# 3. Extra manifest entry (tracked file removed) is rejected.
git rm -q added-later.php && git commit -qm remove-file
if bash scripts/verify-manifest.sh >/dev/null 2>&1; then echo 'FAIL: stale extra manifest entry accepted' >&2; exit 1; fi
bash scripts/regenerate-manifest.sh >/dev/null && git add MANIFEST.sha256 && git commit -qm manifest3

# 4. Modified tracked content is rejected.
printf '<?php // tampered\n' > runtime.php
git add runtime.php && git commit -qm tamper
if bash scripts/verify-manifest.sh >/dev/null 2>&1; then echo 'FAIL: modified content accepted' >&2; exit 1; fi
bash scripts/regenerate-manifest.sh >/dev/null && git add MANIFEST.sha256 && git commit -qm manifest4

# 5. Dirty worktree is rejected (verification requires canonical index state).
printf 'dirty' >> runtime.php
if bash scripts/verify-manifest.sh >/dev/null 2>&1; then echo 'FAIL: dirty worktree accepted' >&2; exit 1; fi
git checkout -q -- runtime.php

# 6. The PHP entry point must produce byte-identical output to the canonical script.
cp MANIFEST.sha256 "$TMP/canonical.manifest"
php scripts/regenerate-manifest.php >/dev/null
cmp -s MANIFEST.sha256 "$TMP/canonical.manifest" || { echo 'FAIL: PHP manifest output diverges from canonical script' >&2; exit 1; }
git checkout -q -- MANIFEST.sha256 2>/dev/null || true

# 7. On any read error the PHP entry point must exit nonzero without writing output.
mkdir -p "$TMP/notrepo/scripts"
cp scripts/regenerate-manifest.php scripts/regenerate-manifest.sh scripts/release-file-list.sh "$TMP/notrepo/scripts/"
rm -f "$TMP/notrepo/MANIFEST.sha256"
if (cd "$TMP/notrepo" && GIT_CEILING_DIRECTORIES="$TMP" php scripts/regenerate-manifest.php >/dev/null 2>&1); then
  echo 'FAIL: PHP manifest script succeeded outside a Git checkout' >&2; exit 1
fi
if [ -f "$TMP/notrepo/MANIFEST.sha256" ] && [ -s "$TMP/notrepo/MANIFEST.sha256" ]; then
  echo 'FAIL: PHP manifest script wrote output despite read errors' >&2; exit 1
fi

echo 'Manifest contract tests passed.'
