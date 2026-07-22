#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

find wp-content -type f -name '*.php' -print | sort | while IFS= read -r file; do php -l "$file" >/dev/null; done
find tests -type f -name '*.php' -print | sort | while IFS= read -r file; do php -l "$file" >/dev/null; done
python3 - <<'__JSON_CHECK__'
import json
from pathlib import Path
excluded = {'.git', 'node_modules', 'vendor', 'reports'}
for path in sorted(Path('.').rglob('*.json')):
    if any(part in excluded for part in path.parts):
        continue
    with path.open(encoding='utf-8') as handle:
        json.load(handle)
print('JSON validation passed')
__JSON_CHECK__
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

if grep -RInE --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=tests --exclude='*.md' --exclude='validate.sh' --exclude='validate-env.sh' --exclude='validate-content.py' --exclude='.env.example' --exclude='.env.ci' --exclude='MANIFEST.sha256' '(https?://(www\.)?example\.com|replace-with-|change-me-use-|changeme|your[-_](password|secret|token))' .; then
  echo 'ERROR: placeholder production domains or credentials found in tracked runtime files.' >&2
  exit 1
fi
trailing_files=$(find . \
  \( -path './.git' -o -path './vendor' -o -path './node_modules' -o -path './reports' -o -path './docs/testing/artifacts' -o -path './wp-content/uploads' \) -prune -o \
  -type f \( -name '*.php' -o -name '*.js' -o -name '*.mjs' -o -name '*.cjs' -o -name '*.css' -o -name '*.json' -o -name '*.md' -o -name '*.txt' -o -name '*.csv' -o -name '*.xml' -o -name '*.yml' -o -name '*.yaml' -o -name '*.sh' -o -name '*.py' -o -name '*.dist' -o -name '*.example' -o -name '*.ci' -o -name 'Makefile' -o -name '.gitignore' -o -name '.gitattributes' -o -name '.npmrc' \) -print0 \
  | xargs -0 grep -Il '[[:blank:]]$' || true)
if [ -n "$trailing_files" ]; then
  echo 'ERROR: trailing whitespace found.' >&2
  printf '%s\n' "$trailing_files"
  exit 1
fi

if [ -f MANIFEST.sha256 ]; then
	./scripts/verify-manifest.sh
fi

echo 'Repository validation passed.'
