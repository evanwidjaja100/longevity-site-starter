#!/bin/sh
set -eu
SITE_URL=${WP_SITE_URL:-http://localhost:8080}
health=$(curl --fail --silent --show-error "$SITE_URL/wp-json/longevity/v1/health")
[ "$health" = '{"status":"ok"}' ] || { echo "Unexpected health payload: $health" >&2; exit 1; }
index=$(curl --fail --silent --show-error "$SITE_URL/wp-json/")
for route in '/wp/v2/lel_claims' '/wp/v2/lel_test_records' '/wp/v2/lel_affiliates'; do
  printf '%s' "$index" | grep -Fq "$route" && { echo "Private route exposed: $route" >&2; exit 1; }
done
schema=$(curl --fail --silent --show-error "$SITE_URL/wp-json/wp/v2/types/post?context=view")
for key in medical_review_required credential_verification_evidence_ref approval_snapshot_hash raw_observations; do
  printf '%s' "$schema" | grep -Fq "$key" && { echo "Private field exposed in public schema: $key" >&2; exit 1; }
done
echo 'Anonymous REST public-boundary integration tests passed.'
