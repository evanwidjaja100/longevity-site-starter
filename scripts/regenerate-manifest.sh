#!/usr/bin/env bash
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
bash scripts/release-file-list.sh | while IFS= read -r -d '' file; do
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    git show ":$file" | sha256sum | awk -v path="$file" '{print $1 " *./" path}'
  else
    sha256sum "$file"
  fi
done > "$tmp"
sed 's#  # *./#' "$tmp" > MANIFEST.sha256
printf 'Manifest regenerated with %s entries.\n' "$(wc -l < MANIFEST.sha256 | tr -d ' ')"
