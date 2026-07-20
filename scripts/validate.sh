#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

find wp-content -type f -name '*.php' -print | sort | while IFS= read -r file; do php -l "$file" >/dev/null; done
find tests -type f -name '*.php' -print | sort | while IFS= read -r file; do php -l "$file" >/dev/null; done
find . -type f -name '*.json' ! -path './node_modules/*' ! -path './vendor/*' -print | sort | while IFS= read -r file; do python3 -m json.tool "$file" >/dev/null; done
python3 - <<'__YAML_CHECK__'
from pathlib import Path
try:
    import yaml
except ImportError:
    yaml = None
for path in [Path('compose.yaml'), *Path('.github/workflows').glob('*.yml')]:
    text=path.read_text(encoding='utf-8')
    if not text.strip():
        raise SystemExit(f'Empty YAML: {path}')
    if yaml is not None:
        yaml.safe_load(text)
print('YAML validation passed' if yaml else 'YAML parser unavailable; non-empty YAML files confirmed')
__YAML_CHECK__
find scripts -type f -name '*.sh' -print | sort | while IFS= read -r file; do sh -n "$file"; done
if command -v shellcheck >/dev/null 2>&1; then find scripts -type f -name '*.sh' -print0 | xargs -0 shellcheck; else echo 'ShellCheck unavailable; skipped locally.'; fi
python3 scripts/validate-content.py
python3 scripts/validate-internal-links.py
python3 scripts/validate-freshness.py --no-fail
./tests/integration/environment-validation.sh

if grep -RInE --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=tests/fixtures --exclude='*.md' --exclude='validate.sh' --exclude='validate-env.sh' --exclude='validate-content.py' --exclude='.env.example' --exclude='.env.ci' --exclude='MANIFEST.sha256' '(https?://(www\.)?example\.com|replace-with-|change-me-use-|changeme|your[-_](password|secret|token))' .; then
  echo 'ERROR: placeholder production domains or credentials found in tracked runtime files.' >&2
  exit 1
fi
if find . -type f ! -path './.git/*' ! -path './vendor/*' ! -path './node_modules/*' -print0 | xargs -0 grep -Il '[[:blank:]]$' | grep -q .; then
  echo 'ERROR: trailing whitespace found.' >&2
  find . -type f ! -path './.git/*' ! -path './vendor/*' ! -path './node_modules/*' -print0 | xargs -0 grep -Il '[[:blank:]]$'
  exit 1
fi

if [ -f MANIFEST.sha256 ]; then
  duplicate_count=$(sed 's/^[^ ]*  //' MANIFEST.sha256 | sort | uniq -d | wc -l | tr -d ' ')
  [ "$duplicate_count" -eq 0 ] || { echo 'ERROR: duplicate manifest entries.' >&2; exit 1; }
  manifest_tmp=$(mktemp)
  trap 'rm -f "$manifest_tmp"' EXIT HUP INT TERM
  find . -type f \
    ! -path './.git/*' \
    ! -path './vendor/*' \
    ! -path './node_modules/*' \
    ! -name 'MANIFEST.sha256' \
    ! -name '.env' \
    -print0 | sort -z | xargs -0 sha256sum > "$manifest_tmp"
  if ! cmp -s MANIFEST.sha256 "$manifest_tmp"; then
    echo 'ERROR: MANIFEST.sha256 is out of date. Run make manifest.' >&2
    exit 1
  fi
  rm -f "$manifest_tmp"
  trap - EXIT HUP INT TERM
fi

echo 'Repository validation passed.'
