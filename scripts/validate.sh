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
find scripts -type f -name '*.sh' -print | sort | while IFS= read -r file; do
  if head -1 "$file" | grep -q 'bash'; then bash -n "$file"; else sh -n "$file"; fi
done
if command -v shellcheck >/dev/null 2>&1; then
  find scripts -type f -name '*.sh' -print | sort | while IFS= read -r file; do
    if head -1 "$file" | grep -q 'bash'; then shellcheck --shell=bash "$file"; else shellcheck --shell=sh "$file"; fi
  done
else echo 'ShellCheck unavailable; skipped locally.'; fi
python3 scripts/validate-content.py
python3 scripts/validate-internal-links.py
python3 scripts/validate-freshness.py --no-fail
bash tests/integration/environment-validation.sh

placeholder_matches=$(git grep -nI -E '(https?://(www\.)?example\.com|replace-with-|change-me-use-|changeme|your[-_](password|secret|token))' -- . 2>/dev/null || true)
if [ -n "$placeholder_matches" ]; then
	filtered=$(printf '%s\n' "$placeholder_matches" | grep -vE '\.md:|scripts/validate\.sh:|scripts/validate-env\.sh:|scripts/validate-content\.py:|scripts/ci-generate-env\.sh:|\.env\.example:|\.env\.ci\.template:|\.env\.production\.example:|MANIFEST\.sha256:|tests/')
	if [ -n "$filtered" ]; then
		echo 'ERROR: placeholder production domains or credentials found in tracked runtime files.' >&2
		printf '%s\n' "$filtered" >&2
		exit 1
	fi
fi
# Check only files tracked by Git. This excludes dependencies, generated
# reports, local tooling, runtime files, and other untracked artifacts.
trailing_matches=$(git grep -nI -E '[[:blank:]]+$' -- . || true)
if [ -n "$trailing_matches" ]; then
  echo 'ERROR: trailing whitespace found in tracked files.' >&2
  printf '%s\n' "$trailing_matches"
  exit 1
fi

# Plugin/theme allowlist: fail if unapproved third-party code is present.
# Uses git ls-files so only tracked entries are checked (runtime-generated
# WordPress defaults like akismet/hello.php are not tracked and thus excluded).
allowed_plugins='index.php'
allowed_themes='index.php longevity-starter'
for entry in $(git ls-files -- wp-content/plugins/ | cut -d/ -f1-3 | sort -u); do
  base=$(basename "$entry")
  case " $allowed_plugins " in *" $base "*) ;; *)
    echo "ERROR: plugin not in allowlist: $entry" >&2; exit 1 ;;
  esac
done
for entry in $(git ls-files -- wp-content/themes/ | cut -d/ -f1-3 | sort -u); do
  base=$(basename "$entry")
  case " $allowed_themes " in *" $base "*) ;; *)
    echo "ERROR: theme not in allowlist: $entry" >&2; exit 1 ;;
  esac
done

echo 'Repository validation passed.'
