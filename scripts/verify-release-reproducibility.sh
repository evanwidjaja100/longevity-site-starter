#!/usr/bin/env bash
# Build twice with independent temporary output trees and compare exact bytes.
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
source_sha=$(git rev-parse --verify "${1:-HEAD}^{commit}")
source_date_epoch=$(git show -s --format=%ct "$source_sha")
report=${2:-build/release/reproducibility.json}
one=$(mktemp -d "${TMPDIR:-/tmp}/lel-build-one.XXXXXXXX")
two=$(mktemp -d "${TMPDIR:-/tmp}/lel-build-two.XXXXXXXX")
trap 'rm -rf "$one" "$two"' EXIT
RELEASE_OUT_DIR="$one" bash scripts/build-release-artifact.sh "$source_sha" >/dev/null
RELEASE_OUT_DIR="$two" bash scripts/build-release-artifact.sh "$source_sha" >/dev/null
a="$one/longevity-release-${source_sha}.tar.gz"
b="$two/longevity-release-${source_sha}.tar.gz"
cmp -s "$a" "$b" || { echo 'ERROR: isolated builds differ.' >&2; exit 1; }
sha=$(sha256sum "$a" | awk '{print $1}')
mkdir -p "$(dirname "$report")"
printf '{"schema_version":1,"result":"success","source_sha":"%s","source_date_epoch":%s,"builds":2,"artifact_sha256":"%s"}\n' "$source_sha" "$source_date_epoch" "$sha" > "$report"
printf 'Reproducible artifact: %s\n' "$sha"
