#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
[ -f MANIFEST.sha256 ] || { echo 'ERROR: MANIFEST.sha256 is missing.' >&2; exit 1; }

expected=$(mktemp)
manifest=$(mktemp)
trap 'rm -f "$expected" "$manifest"' EXIT
./scripts/release-file-list.sh | tr '\0' '\n' > "$expected"
sed -E 's/^[0-9a-f]{64} [ *](\.\/)?//' MANIFEST.sha256 | sort > "$manifest"
if ! diff -u "$expected" "$manifest"; then
  echo 'ERROR: manifest entries do not match the release file set.' >&2
  exit 1
fi
sha256sum -c MANIFEST.sha256
echo 'Manifest entries and hashes are valid.'
