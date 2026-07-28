#!/usr/bin/env bash
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lel-sast-test.XXXXXXXX")
trap 'rm -rf "$TMP"' EXIT
printf '<?php /** @psalm-suppress TaintedHtml */\n' > "$TMP/missing.php"
if (cd "$ROOT" && SAST_SUPPRESSION_SCOPE="$TMP" bash scripts/verify-sast-detection.sh --suppressions-only >/dev/null 2>&1); then echo 'FAIL: ownerless suppression accepted' >&2; exit 1; fi
printf '<?php /** @psalm-suppress TaintedHtml reason="fixture" owner="@security" expires="2020-01-01" */\n' > "$TMP/expired.php"
rm "$TMP/missing.php"
if (cd "$ROOT" && SAST_SUPPRESSION_SCOPE="$TMP" bash scripts/verify-sast-detection.sh --suppressions-only >/dev/null 2>&1); then echo 'FAIL: expired suppression accepted' >&2; exit 1; fi
printf '<?php /** @psalm-suppress TaintedHtml expires="2099-01-01" reason="fixture" owner="@security" */\n' > "$TMP/valid.php"
rm "$TMP/expired.php"
(cd "$ROOT" && SAST_SUPPRESSION_SCOPE="$TMP" bash scripts/verify-sast-detection.sh --suppressions-only >/dev/null)
echo 'SAST control tests passed.'
