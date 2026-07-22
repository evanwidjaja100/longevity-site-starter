#!/bin/sh
set -eu

required="WP_SITE_URL WP_SITE_TITLE WP_ADMIN_USER WP_ADMIN_PASSWORD WP_ADMIN_EMAIL WP_ENVIRONMENT_TYPE WP_TIMEZONE WP_LOCALE"
for key in $required; do
  eval "value=\${$key-}"
  [ -n "$value" ] || { echo "ERROR: $key is required." >&2; exit 1; }
done

case "$(printf '%s' "$WP_ADMIN_USER" | tr '[:upper:]' '[:lower:]')" in
  admin|administrator|root|wordpress|wpadmin|site_admin)
    echo "ERROR: Refusing a commonly targeted administrator username." >&2
    exit 1
    ;;
esac

[ "${#WP_ADMIN_PASSWORD}" -ge 16 ] || { echo "ERROR: WP_ADMIN_PASSWORD must be at least 16 characters." >&2; exit 1; }
printf '%s' "$WP_ADMIN_PASSWORD" | grep -Eiq '(change-me|replace|password|placeholder)' && { echo "ERROR: WP_ADMIN_PASSWORD is a placeholder." >&2; exit 1; }
printf '%s' "$WP_ADMIN_EMAIL" | grep -Eq '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$' || { echo "ERROR: WP_ADMIN_EMAIL is invalid." >&2; exit 1; }

# Use PHP's database driver for readiness. The MariaDB client bundled with the
# WP-CLI image can reject MySQL 8.4's self-signed development certificate even
# though WordPress can connect successfully.
until php -r '
$connection = @mysqli_connect(
    getenv("WORDPRESS_DB_HOST"),
    getenv("WORDPRESS_DB_USER"),
    getenv("WORDPRESS_DB_PASSWORD"),
    getenv("WORDPRESS_DB_NAME")
);
exit($connection ? 0 : 1);
'; do
  echo "Waiting for the database..."
  sleep 3
done

if ! wp core is-installed --allow-root; then
  wp core install \
    --url="$WP_SITE_URL" \
    --title="$WP_SITE_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --locale="$WP_LOCALE" \
    --skip-email \
    --allow-root
fi

wp theme activate longevity-starter --allow-root
wp rewrite structure '/%postname%/' --hard --allow-root
wp option update blogdescription 'Transparent evidence reviews and reproducible consumer testing with clearly stated uncertainty.' --allow-root
wp option update timezone_string "$WP_TIMEZONE" --allow-root
wp option update default_comment_status closed --allow-root
wp option update default_ping_status closed --allow-root
wp option update users_can_register 0 --allow-root
wp option update blog_public 0 --allow-root

create_page() {
  title=$1
  slug=$2
  status=$3
  content=$4
  page_id=$(wp post list --post_type=page --name="$slug" --field=ID --allow-root | head -n1 || true)
  if [ -z "$page_id" ]; then
    wp post create --post_type=page --post_status="$status" --post_title="$title" --post_name="$slug" --post_content="$content" --porcelain --allow-root
  else
    printf '%s' "$page_id"
  fi
}

home_content='<!-- wp:heading {"level":1} --><h1>Evaluate health practices and consumer products with better evidence.</h1><!-- /wp:heading --><!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">Transparent evidence reviews, reproducible testing methods, and clearly stated uncertainty without individualized medical advice or guaranteed longevity outcomes.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/editorial-policy/">Read our editorial standards</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
home_id=$(create_page 'Start Here' 'start-here' publish "$home_content")

editorial_content=$(cat /project-content/editorial-policy.md)
medical_content=$(cat /project-policies/medical-disclaimer.md)
affiliate_content=$(cat /project-policies/affiliate-disclosure.md)
corrections_content=$(cat /project-content/governance/corrections-policy.md)
testing_content=$(cat /project-content/testing/wearables-protocol.md; printf '\n\n'; cat /project-content/testing/consumer-apps-protocol.md; printf '\n\n'; cat /project-content/testing/home-equipment-protocol.md)

create_page 'About' 'about' draft 'Document ownership, funding, named team members, and governance before publication.' >/dev/null
create_page 'Editorial Policy' 'editorial-policy' draft "$editorial_content" >/dev/null
create_page 'Medical Disclaimer' 'medical-disclaimer' draft "$medical_content" >/dev/null
create_page 'Affiliate Disclosure' 'affiliate-disclosure' draft "$affiliate_content" >/dev/null
create_page 'Corrections' 'corrections' draft "$corrections_content" >/dev/null
create_page 'Testing Methodology' 'testing-methodology' draft "$testing_content" >/dev/null
create_page 'Privacy' 'privacy' draft 'Replace this staging page with jurisdiction-reviewed privacy language that reflects the actual vendors and consent configuration.' >/dev/null
create_page 'Terms' 'terms' draft 'Add jurisdiction-reviewed terms before production publication.' >/dev/null
create_page 'Contact' 'contact' draft 'Add monitored editorial, corrections, privacy, and commercial contact channels before production publication.' >/dev/null

wp option update show_on_front page --allow-root
wp option update page_on_front "$home_id" --allow-root

# Categories are managed by the wp longevity bootstrap categories CLI command
# (defined in longevity-core). This replaces the old shell-based category creation.
wp longevity bootstrap categories --allow-root

if [ "${INSTALL_OPTIONAL_PLUGINS:-0}" = "1" ]; then
  wp plugin install wordpress-seo --activate --allow-root || true
  wp plugin install google-site-kit --activate --allow-root || true
fi

wp cache flush --allow-root
printf '\nBootstrap complete: %s\n' "$WP_SITE_URL"
printf 'Policy and legal pages remain drafts until accountable human approval.\n'
printf 'Search-engine visibility remains disabled; enable it only at production launch.\n'
