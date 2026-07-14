#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
find . -type f \
  ! -path './.git/*' \
  ! -path './vendor/*' \
  ! -path './node_modules/*' \
  ! -name 'MANIFEST.sha256' \
  ! -name '.env' \
  -print0 | sort -z | xargs -0 sha256sum > MANIFEST.sha256
printf 'Manifest regenerated with %s entries.\n' "$(wc -l < MANIFEST.sha256 | tr -d ' ')"
