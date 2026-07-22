#!/usr/bin/env bash
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
./scripts/release-file-list.sh | while IFS= read -r -d '' file; do
  sha256sum "$file"
done > "$tmp"
sed 's#  # *./#' "$tmp" > MANIFEST.sha256
printf 'Manifest regenerated with %s entries.\n' "$(wc -l < MANIFEST.sha256 | tr -d ' ')"
