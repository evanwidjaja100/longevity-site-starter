#!/usr/bin/env bash
# Materialize a CI .env from the non-secret template, generating ephemeral
# high-entropy secrets per run. Secrets never touch the repository.
#
# Usage: scripts/ci-generate-env.sh [OUT_ENV_FILE]
#
# When $GITHUB_ENV is set (GitHub Actions), the generated secrets are also
# exported so later steps can reference them.
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TEMPLATE="$ROOT/.env.ci.template"
OUT=${1:-"$ROOT/.env"}

[ -f "$TEMPLATE" ] || { echo "ERROR: $TEMPLATE not found." >&2; exit 1; }

gen_secret() {
  # 48 hex chars: well above the 16-char minimum, no placeholder words.
  openssl rand -hex 24
}

DB_PASSWORD=$(gen_secret)
DB_ROOT_PASSWORD=$(gen_secret)
ADMIN_PASSWORD=$(gen_secret)

# Root and app passwords must differ (validate-env.sh enforces this).
while [ "$DB_PASSWORD" = "$DB_ROOT_PASSWORD" ]; do
  DB_ROOT_PASSWORD=$(gen_secret)
done

cp "$TEMPLATE" "$OUT"
{
  printf 'WORDPRESS_DB_PASSWORD=%s\n' "$DB_PASSWORD"
  printf 'WORDPRESS_DB_ROOT_PASSWORD=%s\n' "$DB_ROOT_PASSWORD"
  printf 'WP_ADMIN_PASSWORD=%s\n' "$ADMIN_PASSWORD"
} >> "$OUT"
chmod 600 "$OUT" 2>/dev/null || true

if [ -n "${GITHUB_ENV:-}" ]; then
  {
    printf 'WORDPRESS_DB_PASSWORD=%s\n' "$DB_PASSWORD"
    printf 'WORDPRESS_DB_ROOT_PASSWORD=%s\n' "$DB_ROOT_PASSWORD"
    printf 'WP_ADMIN_PASSWORD=%s\n' "$ADMIN_PASSWORD"
  } >> "$GITHUB_ENV"
fi

echo "Generated ephemeral CI environment at $OUT (secrets not printed)."
