#!/usr/bin/env bash
# Verify archive identity, allowlist, metadata, modes, and exact staged bytes.
set -euo pipefail
export LC_ALL=C TZ=UTC

artifact=${1:?usage: verify-release-artifact.sh ARTIFACT [EXPECTED_COMMIT] [REPORT_JSON] [SHA256_SIDECAR]}
expected=${2:-}
report=${3:-}
sidecar=${4:-}
[[ -f "$artifact" && -s "$artifact" ]] || { echo 'ERROR: release artifact is missing or empty.' >&2; exit 1; }
TAR_BIN=tar
if ! tar --version 2>/dev/null | grep -q 'GNU tar'; then
  if command -v gtar >/dev/null 2>&1 && gtar --version | grep -q 'GNU tar'; then TAR_BIN=gtar; else echo 'ERROR: GNU tar is required.' >&2; exit 1; fi
fi

tmp=$(mktemp -d "${TMPDIR:-/tmp}/lel-verify.XXXXXXXX")
trap 'rm -rf "$tmp"' EXIT
listing="$tmp/listing.txt"
"$TAR_BIN" -tvzf "$artifact" --numeric-owner > "$listing"

if "$TAR_BIN" -tzf "$artifact" | grep -Eq '(^/|(^|/)\.\.(/|$)|\\)'; then
  echo 'ERROR: archive contains an absolute, traversal, or backslash path.' >&2; exit 1
fi
if grep -Eq '^l|^h' "$listing"; then echo 'ERROR: archive contains a link.' >&2; exit 1; fi
if "$TAR_BIN" -tzf "$artifact" | grep -Ev '^(RELEASE-INFO\.txt|RELEASE-MANIFEST\.sha256|wp-content/?|wp-content/mu-plugins/?|wp-content/themes/?|wp-content/mu-plugins/longevity-core\.php|wp-content/mu-plugins/longevity-core(/.*)?|wp-content/themes/longevity-starter(/.*)?)$' | grep -q .; then
  echo 'ERROR: archive contains a path outside the runtime allowlist.' >&2; exit 1
fi
if "$TAR_BIN" -tzf "$artifact" | grep -Ei '(^|/)(\.env($|\.)|tests?(/|$)|vendor(/|$)|node_modules(/|$)|[^/]+\.(sql(\.gz)?|pem|key|log|map)$|composer\.(json|lock)$|package(-lock)?\.json$)' | grep -q .; then
  echo 'ERROR: archive contains a secret, dump, or development path.' >&2; exit 1
fi
if awk '$1 ~ /^d/ && $1 != "drwxr-xr-x" {bad=1} $1 ~ /^-/ && $1 != "-rw-r--r--" {bad=1} END {exit bad ? 0 : 1}' "$listing"; then
  echo 'ERROR: archive permissions are not normalized to 0755/0644.' >&2; exit 1
fi

mkdir -p "$tmp/extracted"
"$TAR_BIN" -xzf "$artifact" -C "$tmp/extracted"
info="$tmp/extracted/RELEASE-INFO.txt"
manifest="$tmp/extracted/RELEASE-MANIFEST.sha256"
[[ -s "$info" && -s "$manifest" ]] || { echo 'ERROR: embedded release metadata is missing.' >&2; exit 1; }
source_sha=$(sed -n 's/^source_sha=//p' "$info")
source_tree=$(sed -n 's/^source_tree=//p' "$info")
source_date_epoch=$(sed -n 's/^source_date_epoch=//p' "$info")
[[ "$source_sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'ERROR: malformed embedded source SHA.' >&2; exit 1; }
[[ "$source_tree" =~ ^[0-9a-f]{40}$ && "$source_date_epoch" =~ ^[0-9]+$ ]] || { echo 'ERROR: malformed embedded source tree or timestamp.' >&2; exit 1; }
[[ -z "$expected" || "$source_sha" = "$expected" ]] || { echo 'ERROR: embedded source SHA does not match expected commit.' >&2; exit 1; }
if [[ -n "$expected" ]] && git cat-file -e "${expected}^{commit}" 2>/dev/null; then
  [[ "$source_tree" = "$(git rev-parse "${expected}^{tree}")" ]] || { echo 'ERROR: embedded source tree does not match expected commit.' >&2; exit 1; }
  [[ "$source_date_epoch" = "$(git show -s --format=%ct "$expected")" ]] || { echo 'ERROR: embedded timestamp is not the source commit timestamp.' >&2; exit 1; }
fi
(cd "$tmp/extracted" && sha256sum -c RELEASE-MANIFEST.sha256 >/dev/null)
manifest_count=$(wc -l < "$manifest" | tr -d '[:space:]')
actual_count=$(find "$tmp/extracted/wp-content" -type f | wc -l | tr -d '[:space:]')
[[ "$manifest_count" = "$actual_count" ]] || { echo 'ERROR: embedded manifest does not cover every runtime file exactly once.' >&2; exit 1; }
sed -E 's/^[a-f0-9]{64} [ *]//' "$manifest" | sort > "$tmp/manifest-paths"
(cd "$tmp/extracted" && find wp-content -type f | sort) > "$tmp/actual-paths"
cmp -s "$tmp/manifest-paths" "$tmp/actual-paths" || { echo 'ERROR: embedded manifest path set is incomplete or duplicated.' >&2; exit 1; }

sha=$(sha256sum "$artifact" | awk '{print $1}')
if [[ -n "$sidecar" ]]; then
  [[ -f "$sidecar" ]] || { echo 'ERROR: artifact checksum sidecar is missing.' >&2; exit 1; }
  expected_line="$sha  $(basename "$artifact")"
  [[ "$(cat "$sidecar")" = "$expected_line" ]] || { echo 'ERROR: artifact checksum sidecar mismatch.' >&2; exit 1; }
fi
if [[ -n "$report" ]]; then
  mkdir -p "$(dirname "$report")"
  printf '{"schema_version":1,"result":"success","source_sha":"%s","source_tree":"%s","source_date_epoch":%s,"artifact_sha256":"%s","manifest_files":%s,"links":0,"unsafe_paths":0,"mode_policy":"directories=0755,files=0644"}\n' "$source_sha" "$source_tree" "$source_date_epoch" "$sha" "$manifest_count" > "$report"
fi
printf 'Release artifact verified: %s (%s)\n' "$artifact" "$sha"
