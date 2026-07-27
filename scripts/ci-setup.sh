#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

env_value() {
  local key=$1
  local value=${!key-}
  if [[ -z "$value" && -f .env ]]; then
    value=$(sed -n "s/^${key}=//p" .env | tail -n 1)
    value=${value#\"}
    value=${value%\"}
  fi
  printf '%s' "$value"
}

environment_type=$(env_value WP_ENVIRONMENT_TYPE)
site_url=$(env_value WP_SITE_URL)

case "$environment_type" in
  ''|production)
    echo 'ERROR: CI setup requires an explicit non-production WP_ENVIRONMENT_TYPE.' >&2
    exit 1
    ;;
esac

[[ -n "$site_url" ]] || { echo 'ERROR: WP_SITE_URL is required for CI setup.' >&2; exit 1; }
mkdir -p reports/playwright reports/lighthouse reports/ci-setup

docker compose up -d db wordpress

ready=0
for attempt in {1..40}; do
  if curl --fail --silent --show-error --location --output /dev/null "$site_url/"; then
    ready=1
    break
  fi
  sleep 3
done
[[ "$ready" -eq 1 ]] || { echo 'ERROR: WordPress did not become HTTP-ready within the CI budget.' >&2; exit 1; }

docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh
docker compose run --rm wpcli longevity migrate --allow-root
docker compose run --rm --entrypoint sh wpcli /scripts/create-test-fixtures.sh

for route in / /test-evidence-guide/ /reviews/; do
  status=$(curl --fail --silent --show-error --location --output /dev/null --write-out '%{http_code}' "$site_url$route")
  [[ "$status" = 200 ]] || { echo "ERROR: expected fixture route $route to return 200, got $status." >&2; exit 1; }
done

published_guides=$(docker compose run --rm wpcli post list --post_type=post --post_status=publish --format=count --allow-root | tr -d '[:space:]')
published_reviews=$(docker compose run --rm wpcli post list --post_type=review --post_status=publish --format=count --allow-root | tr -d '[:space:]')
blocked_status=$(docker compose run --rm wpcli post get test-blocked-review --field=post_status --allow-root | tr -d '[:space:]')
[[ "$published_guides" -ge 2 ]] || { echo "ERROR: expected at least 2 published synthetic guides, got $published_guides." >&2; exit 1; }
[[ "$published_reviews" -ge 3 ]] || { echo "ERROR: expected at least 3 published synthetic reviews, got $published_reviews." >&2; exit 1; }
[[ "$blocked_status" != publish ]] || { echo 'ERROR: incomplete synthetic review was published.' >&2; exit 1; }

{
  printf 'environment=%s\n' "$environment_type"
  printf 'fixture_source_sha256=%s\n' "$(sha256sum scripts/create-test-fixtures.php | awk '{print $1}')"
  printf 'published_guides=%s\n' "$published_guides"
  printf 'published_reviews=%s\n' "$published_reviews"
  printf 'blocked_review_status=%s\n' "$blocked_status"
} > reports/ci-setup/fixture-version.txt

echo 'Canonical CI setup and synthetic fixtures are ready.'
