#!/usr/bin/env bash
# Package only first-party runtime files into a verifiable release artifact.
# The same artifact bytes must be deployed to managed staging and, after
# manual approval, promoted unchanged to production.
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

SOURCE_SHA=${1:-$(git rev-parse HEAD 2>/dev/null || echo unknown)}
OUT_DIR=${RELEASE_OUT_DIR:-build/release}
STAGE_DIR="$OUT_DIR/stage"
ARTIFACT="$OUT_DIR/longevity-release-${SOURCE_SHA}.tar.gz"

RUNTIME_PATHS=(
  "wp-content/mu-plugins/longevity-core.php"
  "wp-content/mu-plugins/longevity-core"
  "wp-content/themes/longevity-starter"
)

for path in "${RUNTIME_PATHS[@]}"; do
  [[ -e "$path" ]] || { echo "ERROR: required runtime path missing: $path" >&2; exit 1; }
done

rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR"

for path in "${RUNTIME_PATHS[@]}"; do
  mkdir -p "$STAGE_DIR/$(dirname "$path")"
  cp -R "$path" "$STAGE_DIR/$path"
done

# Strip non-runtime files that may sit inside runtime directories.
find "$STAGE_DIR" -type f \( -name '*.log' -o -name '*.map' -o -name '.DS_Store' \) -delete

MANIFEST="$OUT_DIR/release-manifest-${SOURCE_SHA}.sha256"
( cd "$STAGE_DIR" && find . -type f -print0 | sort -z | xargs -0 sha256sum ) > "$MANIFEST"

FILE_COUNT=$(wc -l < "$MANIFEST" | tr -d '[:space:]')
[[ "$FILE_COUNT" -gt 0 ]] || { echo 'ERROR: release manifest is empty.' >&2; exit 1; }

tar -czf "$ARTIFACT" -C "$STAGE_DIR" wp-content
ARTIFACT_SHA=$(sha256sum "$ARTIFACT" | awk '{print $1}')
printf '%s  %s\n' "$ARTIFACT_SHA" "$(basename "$ARTIFACT")" > "$ARTIFACT.sha256"

{
  printf 'source_sha=%s\n' "$SOURCE_SHA"
  printf 'artifact=%s\n' "$(basename "$ARTIFACT")"
  printf 'artifact_sha256=%s\n' "$ARTIFACT_SHA"
  printf 'file_count=%s\n' "$FILE_COUNT"
  printf 'built_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$OUT_DIR/release-info-${SOURCE_SHA}.txt"

rm -rf "$STAGE_DIR"

echo "Release artifact: $ARTIFACT"
echo "Artifact sha256:  $ARTIFACT_SHA"
echo "Manifest entries: $FILE_COUNT"
