#!/bin/sh
set -eu

environment_type=${WP_ENVIRONMENT_TYPE:-}
[ -n "$environment_type" ] || { echo 'ERROR: WP_ENVIRONMENT_TYPE is required for synthetic fixtures.' >&2; exit 1; }
case "$environment_type" in
  local|development)
    ;;
  *)
    echo "ERROR: Synthetic fixtures refuse the '$environment_type' environment; only local or development may host the CI fixture projection." >&2
    exit 1
    ;;
esac

wp eval-file /scripts/create-test-fixtures.php --user="$WP_ADMIN_USER" --allow-root
