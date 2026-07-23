#!/usr/bin/env bash
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
[ -f MANIFEST.sha256 ] || { echo 'ERROR: MANIFEST.sha256 is missing.' >&2; exit 1; }

expected=$(mktemp)
manifest=$(mktemp)
actual=$(mktemp)
trap 'rm -f "$expected" "$manifest" "$actual" "$actual.sorted"' EXIT
bash scripts/release-file-list.sh | tr '\0' '\n' > "$expected"
sed -E 's/^[0-9a-f]{64} [ *](\.\/)?//' MANIFEST.sha256 | sort > "$manifest"
if ! diff -u "$expected" "$manifest"; then
  echo 'ERROR: manifest entries do not match the release file set.' >&2
  exit 1
fi

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git diff --quiet || { echo 'ERROR: manifest verification requires a clean worktree.' >&2; exit 1; }
  git diff --cached --quiet || { echo 'ERROR: manifest verification requires a clean index.' >&2; exit 1; }
  while IFS= read -r file; do
    hash=$(git show ":$file" | sha256sum | awk '{print $1}')
    printf '%s *./%s\n' "$hash" "$file" >> "$actual"
  done < "$expected"
else
  sha256sum -c MANIFEST.sha256
  cp MANIFEST.sha256 "$actual"
fi

sort "$actual" > "$actual.sorted"
if ! diff -u <(sort MANIFEST.sha256) "$actual.sorted"; then
  echo 'ERROR: manifest hashes do not match canonical Git/index bytes.' >&2
  exit 1
fi
echo 'Manifest entries and hashes are valid.'
