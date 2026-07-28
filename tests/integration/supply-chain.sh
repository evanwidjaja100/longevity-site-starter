#!/usr/bin/env bash
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lel-sbom-test.XXXXXXXX")
trap 'rm -rf "$TMP"' EXIT
cp "$ROOT/scripts/generate-dependency-sbom.php" "$TMP/generate.php"
cat > "$TMP/composer.lock" <<'JSON'
{"packages":[],"packages-dev":[{"name":"vendor/tool","version":"1.0.0","license":["OSL-3.0"]}]}
JSON
cat > "$TMP/package-lock.json" <<'JSON'
{"lockfileVersion":3,"packages":{"":{"devDependencies":{"tool":"1.0.0"}},"node_modules/tool":{"version":"1.0.0","license":"Apache-2.0","dev":true}}}
JSON
(cd "$TMP" && php generate.php sbom.json licenses.json >/dev/null)
php -r '$s=json_decode(file_get_contents($argv[1]),true);$l=json_decode(file_get_contents($argv[2]),true);exit(($s["spdxVersion"]??"")==="SPDX-2.3"&&count($s["packages"]??[])===2&&($l["result"]??"")==="success"?0:1);' "$TMP/sbom.json" "$TMP/licenses.json"
sed 's/Apache-2.0/AGPL-3.0/' "$TMP/package-lock.json" > "$TMP/package-lock.bad" && mv "$TMP/package-lock.bad" "$TMP/package-lock.json"
if (cd "$TMP" && php generate.php sbom.json licenses.json >/dev/null 2>&1); then echo 'FAIL: prohibited license accepted' >&2; exit 1; fi
sed 's/AGPL-3.0/OSL-3.0/;s/"dev":true/"dev":false/' "$TMP/package-lock.json" > "$TMP/package-lock.bad" && mv "$TMP/package-lock.bad" "$TMP/package-lock.json"
if (cd "$TMP" && php generate.php sbom.json licenses.json >/dev/null 2>&1); then echo 'FAIL: development-only license accepted for runtime dependency' >&2; exit 1; fi
echo 'Supply-chain tests passed.'
