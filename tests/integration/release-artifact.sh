#!/usr/bin/env bash
set -euo pipefail
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lel-artifact-test.XXXXXXXX")
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/repo/scripts" "$TMP/repo/wp-content/mu-plugins/longevity-core" "$TMP/repo/wp-content/themes/longevity-starter"
cp "$ROOT/scripts/build-release-artifact.sh" "$ROOT/scripts/verify-release-artifact.sh" "$ROOT/scripts/verify-release-reproducibility.sh" "$TMP/repo/scripts/"
printf '<?php // loader\n' > "$TMP/repo/wp-content/mu-plugins/longevity-core.php"
printf '<?php // core\n' > "$TMP/repo/wp-content/mu-plugins/longevity-core/core.php"
printf '<?php // theme\n' > "$TMP/repo/wp-content/themes/longevity-starter/functions.php"
git -C "$TMP/repo" init -q
git -C "$TMP/repo" -c user.name=Test -c user.email=test@example.invalid add .
git -C "$TMP/repo" -c user.name=Test -c user.email=test@example.invalid commit -qm initial
SHA=$(git -C "$TMP/repo" rev-parse HEAD)
EPOCH=$(git -C "$TMP/repo" show -s --format=%ct "$SHA")
if (cd "$TMP/repo" && SOURCE_DATE_EPOCH=$((EPOCH + 1)) RELEASE_OUT_DIR=override bash scripts/build-release-artifact.sh "$SHA" >/dev/null 2>&1); then echo 'FAIL: mismatched SOURCE_DATE_EPOCH accepted' >&2; exit 1; fi
(cd "$TMP/repo" && RELEASE_OUT_DIR=one bash scripts/build-release-artifact.sh "$SHA" >/dev/null)
printf '<?php // dirty workspace must be ignored\n' > "$TMP/repo/wp-content/mu-plugins/longevity-core/core.php"
(cd "$TMP/repo" && RELEASE_OUT_DIR=two bash scripts/build-release-artifact.sh "$SHA" >/dev/null)
cmp "$TMP/repo/one/longevity-release-${SHA}.tar.gz" "$TMP/repo/two/longevity-release-${SHA}.tar.gz"
(cd "$TMP/repo" && bash scripts/verify-release-artifact.sh "one/longevity-release-${SHA}.tar.gz" "$SHA" '' "one/longevity-release-${SHA}.tar.gz.sha256" >/dev/null && bash scripts/verify-release-reproducibility.sh "$SHA" >/dev/null)

git -C "$TMP/repo" add wp-content/mu-plugins/longevity-core/core.php
git -C "$TMP/repo" -c user.name=Test -c user.email=test@example.invalid commit -qm changed
CHANGED=$(git -C "$TMP/repo" rev-parse HEAD)
(cd "$TMP/repo" && RELEASE_OUT_DIR=changed bash scripts/build-release-artifact.sh "$CHANGED" >/dev/null)
if cmp -s "$TMP/repo/one/longevity-release-${SHA}.tar.gz" "$TMP/repo/changed/longevity-release-${CHANGED}.tar.gz"; then echo 'FAIL: changed source produced identical bytes' >&2; exit 1; fi

printf 'secret\n' > "$TMP/repo/wp-content/themes/longevity-starter/.env.production"
git -C "$TMP/repo" add wp-content/themes/longevity-starter/.env.production && git -C "$TMP/repo" -c user.name=Test -c user.email=test@example.invalid commit -qm unsafe
BAD=$(git -C "$TMP/repo" rev-parse HEAD)
if (cd "$TMP/repo" && RELEASE_OUT_DIR=bad bash scripts/build-release-artifact.sh "$BAD" >/dev/null 2>&1); then echo 'FAIL: development/secret file accepted' >&2; exit 1; fi
git -C "$TMP/repo" rm -q wp-content/themes/longevity-starter/.env.production
git -C "$TMP/repo" -c user.name=Test -c user.email=test@example.invalid commit -qm remove-unsafe
LINK_BLOB=$(printf '../../outside' | git -C "$TMP/repo" hash-object -w --stdin)
git -C "$TMP/repo" update-index --add --cacheinfo "120000,$LINK_BLOB,wp-content/themes/longevity-starter/escape"
git -C "$TMP/repo" -c user.name=Test -c user.email=test@example.invalid commit -qm symlink
LINK_SHA=$(git -C "$TMP/repo" rev-parse HEAD)
if (cd "$TMP/repo" && RELEASE_OUT_DIR='link' bash scripts/build-release-artifact.sh "$LINK_SHA" >/dev/null 2>&1); then echo 'FAIL: Git symlink accepted' >&2; exit 1; fi
echo 'Release artifact tests passed.'
