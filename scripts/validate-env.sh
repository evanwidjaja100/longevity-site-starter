#!/usr/bin/env bash
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ENV_FILE=${1:-"$ROOT/.env"}
case "$ENV_FILE" in /*) : ;; *) ENV_FILE="$(pwd)/$ENV_FILE" ;; esac

if [ ! -f "$ENV_FILE" ]; then
  echo "ERROR: Environment file not found: $ENV_FILE" >&2
  exit 1
fi

# Parse dotenv assignments without sourcing the file. Sourcing would execute shell code.
while IFS= read -r line || [ -n "$line" ]; do
  line=$(printf '%s' "$line" | tr -d '\r')
  case "$line" in
    ''|'#'*) continue ;;
  esac
  case "$line" in
    *=*) : ;;
    *) echo "ERROR: Invalid environment line (expected KEY=VALUE)." >&2; exit 1 ;;
  esac
  key=$(printf '%s' "${line%%=*}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  value=$(printf '%s' "${line#*=}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  printf '%s' "$key" | grep -Eq '^[A-Z][A-Z0-9_]*$' || { echo "ERROR: Invalid environment variable name." >&2; exit 1; }
  first=$(printf '%s' "$value" | cut -c1)
  last=$(printf '%s' "$value" | awk '{ print substr($0, length($0), 1) }')
  if [ "$first" = '"' ] && [ "$last" = '"' ]; then
    value=${value#?}
    value=${value%?}
  elif [ "$first" = "'" ] && [ "$last" = "'" ]; then
    value=${value#?}
    value=${value%?}
  fi
  export "$key=$value"
done < "$ENV_FILE"

errors=0
warnings=0
required="WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD WP_SITE_URL WP_SITE_TITLE WP_ADMIN_USER WP_ADMIN_PASSWORD WP_ADMIN_EMAIL WP_ENVIRONMENT_TYPE WP_TIMEZONE WP_LOCALE WP_DEBUG WP_DEBUG_LOG WP_DEBUG_DISPLAY FORCE_SSL_ADMIN DISALLOW_FILE_MODS"

for key in $required; do
  value="${!key-}"
  if [ -z "$value" ]; then
    echo "ERROR: $key is required." >&2
    errors=$((errors + 1))
  fi
done

# DB root credential contract: local/CI Docker needs it to initialize the
# disposable database container; managed staging/production must never carry
# it (least privilege — the application uses only its scoped DB user).
case "${WP_ENVIRONMENT_TYPE-}" in
  local|development)
    if [ -z "${WORDPRESS_DB_ROOT_PASSWORD-}" ]; then
      echo "ERROR: WORDPRESS_DB_ROOT_PASSWORD is required in local/development (Docker database initialization)." >&2
      errors=$((errors + 1))
    fi
    ;;
  staging|production)
    if [ -n "${WORDPRESS_DB_ROOT_PASSWORD-}" ]; then
      echo "ERROR: WORDPRESS_DB_ROOT_PASSWORD must not be set in ${WP_ENVIRONMENT_TYPE}. Managed hosts use only the least-privilege application credential; see docs/operations/database-privileges.md." >&2
      errors=$((errors + 1))
    fi
    ;;
esac

is_placeholder() {
  printf '%s' "$1" | grep -Eiq '(^|[-_])(change|replace|example|password|secret|changeme|placeholder)([-_]|$)|example\.(com|test)|your[-_]'
}

for key in WORDPRESS_DB_PASSWORD WORDPRESS_DB_ROOT_PASSWORD WP_ADMIN_PASSWORD; do
  value="${!key-}"
  if [ -n "$value" ] && is_placeholder "$value"; then
    echo "ERROR: $key still contains a placeholder value." >&2
    errors=$((errors + 1))
  fi
  if [ -n "$value" ] && [ "${#value}" -lt 16 ]; then
    echo "ERROR: $key must be at least 16 characters." >&2
    errors=$((errors + 1))
  fi
done

case "$(printf '%s' "${WP_ADMIN_USER-}" | tr '[:upper:]' '[:lower:]')" in
  admin|administrator|root|wordpress|wpadmin|site_admin)
    echo "ERROR: WP_ADMIN_USER is a commonly targeted username." >&2
    errors=$((errors + 1))
    ;;
esac

if [ -n "${WORDPRESS_DB_PASSWORD-}" ] && [ "${WORDPRESS_DB_PASSWORD-}" = "${WORDPRESS_DB_ROOT_PASSWORD-}" ]; then
  echo "ERROR: Database application and root passwords must differ." >&2
  errors=$((errors + 1))
fi

if ! printf '%s' "${WP_SITE_URL-}" | grep -Eq '^https?://[^[:space:]]+$'; then
  echo "ERROR: WP_SITE_URL must be an absolute HTTP(S) URL." >&2
  errors=$((errors + 1))
fi

if ! printf '%s' "${WP_ADMIN_EMAIL-}" | grep -Eq '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'; then
  echo "ERROR: WP_ADMIN_EMAIL is not a valid email address." >&2
  errors=$((errors + 1))
fi

case "${WP_ENVIRONMENT_TYPE-}" in
  local|development|staging|production) : ;;
  *) echo "ERROR: WP_ENVIRONMENT_TYPE must be local, development, staging, or production." >&2; errors=$((errors + 1));;
esac

for key in WP_DEBUG WP_DEBUG_LOG WP_DEBUG_DISPLAY FORCE_SSL_ADMIN DISALLOW_FILE_MODS; do
  value="${!key-}"
  case "$value" in true|false|1|0) : ;; *) echo "ERROR: $key must be true or false." >&2; errors=$((errors + 1));; esac
done

if [ "${WP_ENVIRONMENT_TYPE-}" != "local" ] && printf '%s' "${WP_SITE_URL-}" | grep -Eq '^http://'; then
  echo "WARNING: HTTP is configured outside a local environment." >&2
  warnings=$((warnings + 1))
fi

if [ -n "${LEL_CSP_ENFORCE-}" ]; then
  echo "ERROR: LEL_CSP_ENFORCE is retired and ignored at runtime. Use LEL_CSP_MODE=report-only|enforce." >&2
  errors=$((errors + 1))
fi

if [ -n "${LEL_CSP_MODE-}" ]; then
  case "$LEL_CSP_MODE" in
    report-only|enforce) : ;;
    *) echo "ERROR: LEL_CSP_MODE must be report-only or enforce." >&2; errors=$((errors + 1));;
  esac
fi

if [ "${WP_ENVIRONMENT_TYPE-}" = "production" ]; then
  [ "${WP_DEBUG_DISPLAY-}" = "false" ] || { echo "ERROR: WP_DEBUG_DISPLAY must be false in production." >&2; errors=$((errors + 1)); }
  [ "${FORCE_SSL_ADMIN-}" = "true" ] || { echo "ERROR: FORCE_SSL_ADMIN must be true in production." >&2; errors=$((errors + 1)); }
  [ "${DISALLOW_FILE_MODS-}" = "true" ] || { echo "ERROR: DISALLOW_FILE_MODS must be true in production." >&2; errors=$((errors + 1)); }
  [ -n "${LEL_CSP_MODE-}" ] || { echo "ERROR: LEL_CSP_MODE must be set explicitly in production (report-only or enforce)." >&2; errors=$((errors + 1)); }
fi

if [ "$errors" -gt 0 ]; then
  echo "Environment validation failed with $errors error(s) and $warnings warning(s)." >&2
  exit 1
fi

echo "Environment validation passed with $warnings warning(s). Secret values were not printed."
