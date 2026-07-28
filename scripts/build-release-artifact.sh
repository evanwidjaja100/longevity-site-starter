#!/usr/bin/env bash
# Build deterministic runtime bytes from a committed Git tree, never the workspace.
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
export LC_ALL=C TZ=UTC

requested=${1:-HEAD}
SOURCE_SHA=$(git rev-parse --verify "${requested}^{commit}") || {
  echo "ERROR: source is not a Git commit: $requested" >&2
  exit 1
}
COMMIT_EPOCH=$(git show -s --format=%ct "$SOURCE_SHA")
[[ "$COMMIT_EPOCH" =~ ^[0-9]+$ ]] || { echo 'ERROR: commit timestamp is invalid.' >&2; exit 1; }
if [[ -n "${SOURCE_DATE_EPOCH:-}" && "$SOURCE_DATE_EPOCH" != "$COMMIT_EPOCH" ]]; then
  echo 'ERROR: SOURCE_DATE_EPOCH must equal the source commit timestamp.' >&2
  exit 1
fi
SOURCE_DATE_EPOCH=$COMMIT_EPOCH
export SOURCE_DATE_EPOCH

OUT_DIR=${RELEASE_OUT_DIR:-build/release}
ARTIFACT="$OUT_DIR/longevity-release-${SOURCE_SHA}.tar.gz"
MANIFEST="$OUT_DIR/release-manifest-${SOURCE_SHA}.sha256"
INFO="$OUT_DIR/release-info-${SOURCE_SHA}.txt"
STAGE=$(mktemp -d "${TMPDIR:-/tmp}/lel-release.XXXXXXXX")
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$OUT_DIR"

TAR_BIN=tar
if ! tar --version 2>/dev/null | grep -q 'GNU tar'; then
  if command -v gtar >/dev/null 2>&1 && gtar --version | grep -q 'GNU tar'; then
    TAR_BIN=gtar
  else
    echo 'ERROR: GNU tar is required.' >&2
    exit 1
  fi
fi

paths=(
  wp-content/mu-plugins/longevity-core.php
  wp-content/mu-plugins/longevity-core
  wp-content/themes/longevity-starter
)
for path in "${paths[@]}"; do
  git cat-file -e "$SOURCE_SHA:$path" 2>/dev/null || { echo "ERROR: runtime path missing from $SOURCE_SHA: $path" >&2; exit 1; }
done
git ls-tree -r -z --name-only "$SOURCE_SHA" -- "${paths[@]}" | while IFS= read -r -d '' path; do
  case "$path" in *\\*|*$'\n'*|*$'\r'*|*$'\t'*) echo "ERROR: unsafe release path: $path" >&2; exit 1;; esac
done

# Git links are forbidden even if they point inside the tree: managed-host extraction
# must not be able to escape or alias the allowlist.
if git ls-tree -r "$SOURCE_SHA" -- "${paths[@]}" | awk '$1 == "120000" { print; bad=1 } END { exit bad ? 0 : 1 }' | grep -q .; then
  echo 'ERROR: release tree contains a symbolic link.' >&2
  exit 1
fi

git archive --format=tar "$SOURCE_SHA" -- "${paths[@]}" | "$TAR_BIN" -xf - -C "$STAGE"

# Reject files that are never runtime inputs, even if accidentally committed below
# an allowlisted directory.
if find "$STAGE" -type f \( -name '.env' -o -name '.env.*' -o -name '*.sql' -o -name '*.sql.gz' -o -name '*.pem' -o -name '*.key' -o -name '*.log' -o -name '*.map' -o -name '.DS_Store' -o -name 'composer.json' -o -name 'composer.lock' -o -name 'package.json' -o -name 'package-lock.json' \) -print -quit | grep -q .; then
  echo 'ERROR: release tree contains a secret, dump, generated, or development file.' >&2
  exit 1
fi
if find "$STAGE" -type d \( -name tests -o -name node_modules -o -name vendor -o -name .git \) -print -quit | grep -q .; then
  echo 'ERROR: release tree contains a development directory.' >&2
  exit 1
fi

find "$STAGE" -type d -exec chmod 0755 {} +
find "$STAGE" -type f -exec chmod 0644 {} +
manifest_tmp=$(mktemp "${TMPDIR:-/tmp}/lel-manifest.XXXXXXXX")
find "$STAGE" -type f -print0 | sed -z 's#^.*/lel-release\.[^/]*/##' | sort -z | while IFS= read -r -d '' file; do
  hash=$(cd "$STAGE" && sha256sum "$file" | awk '{print $1}')
  printf '%s  %s\n' "$hash" "$file"
done > "$manifest_tmp"
mv "$manifest_tmp" "$STAGE/RELEASE-MANIFEST.sha256"
cp "$STAGE/RELEASE-MANIFEST.sha256" "$MANIFEST"

printf 'source_sha=%s\nsource_tree=%s\nsource_date_epoch=%s\n' \
  "$SOURCE_SHA" "$(git rev-parse "$SOURCE_SHA^{tree}")" "$SOURCE_DATE_EPOCH" > "$STAGE/RELEASE-INFO.txt"
chmod 0644 "$STAGE/RELEASE-"*.txt "$STAGE/RELEASE-MANIFEST.sha256"

"$TAR_BIN" --sort=name --format=gnu --owner=0 --group=0 --numeric-owner \
  --mtime="@${SOURCE_DATE_EPOCH}" --mode='go=rX,u=rwX' \
  -cf - -C "$STAGE" RELEASE-INFO.txt RELEASE-MANIFEST.sha256 wp-content | gzip -n > "$ARTIFACT"
ARTIFACT_SHA=$(sha256sum "$ARTIFACT" | awk '{print $1}')
printf '%s  %s\n' "$ARTIFACT_SHA" "$(basename "$ARTIFACT")" > "$ARTIFACT.sha256"
printf 'source_sha=%s\nsource_tree=%s\nsource_date_epoch=%s\nartifact=%s\nartifact_sha256=%s\nfile_count=%s\n' \
  "$SOURCE_SHA" "$(git rev-parse "$SOURCE_SHA^{tree}")" "$SOURCE_DATE_EPOCH" "$(basename "$ARTIFACT")" "$ARTIFACT_SHA" "$(wc -l < "$MANIFEST" | tr -d '[:space:]')" > "$INFO"

printf 'Release artifact: %s\nArtifact sha256:  %s\n' "$ARTIFACT" "$ARTIFACT_SHA"
