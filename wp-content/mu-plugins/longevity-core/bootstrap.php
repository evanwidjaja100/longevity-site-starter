<?php
/**
 * Longevity Core bootstrap.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

$longevity_core_files = array(
	'class-logger.php',
	'class-gate-result.php',
	'class-date-validator.php',
	'class-runtime-config.php',
	'class-routes.php',
	'class-content-types.php',
	'class-roles.php',
	'class-meta-registry.php',
	'class-meta-authorization.php',
	'class-reviewer-credentials.php',
	'class-audit-log.php',
	'class-approval-fingerprint.php',
	'class-approval-repository.php',
	'class-publication-lock.php',
	'class-dependency-index.php',
	'class-invalidation-queue.php',
	'class-approval-service.php',
	'class-claims.php',
	'class-affiliate-registry.php',
	'class-review-methodology.php',
	'class-rankings.php',
	'class-corrections.php',
	'class-publication-gates.php',
	'class-review-workflow.php',
	'class-migrations.php',
	'class-freshness-repository.php',
	'class-freshness.php',
	'class-system-readiness.php',
	'class-metrics.php',
	'class-content-discovery.php',
	'class-public-nav.php',
	'class-public-contact.php',
		'class-public-content.php',
		'class-public-trust.php',
		'class-public-rankings.php',
		'class-blocks.php',
	'class-admin-assets.php',
	'class-admin-ui.php',
	'class-shortcodes.php',
	'class-schema.php',
	'class-analytics.php',
	'class-rest-api.php',
	'class-seo.php',
	'class-cli.php',
);

foreach ( $longevity_core_files as $longevity_core_file ) {
	require_once LONGEVITY_CORE_PATH . $longevity_core_file;
}

/**
 * Starts all first-party services.
 */
final class Bootstrap {
	/**
	 * Prevent duplicate initialization.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Register service hooks.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		Content_Types::init();
		Routes::init();
		SEO::init();
		Roles::init();
		Meta_Registry::init();
		Claims::init();
		Affiliate_Registry::init();
		Review_Methodology::init();
		Rankings::init();
		Corrections::init();
		Publication_Gates::init();
		Review_Workflow::init();
		Approval_Service::init();
		Invalidation_Queue::init();
		Migrations::init();
		Freshness::init();
		Public_Contact::init();
		Content_Discovery::init();
		Public_Content::init();
		Blocks::init();
		longevity_admin_assets_init();
		Admin_UI::init();
		Shortcodes::init();
		Schema::init();
		Analytics::init();
		Rest_API::init();
		CLI::init();

		add_action( 'admin_post_longevity_contact_submit', array( Public_Contact::class, 'handle_contact_submission' ) );
		add_action( 'admin_post_nopriv_longevity_contact_submit', array( Public_Contact::class, 'handle_contact_submission' ) );

		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'wp_robots', array( self::class, 'filter_noindex_placeholder_pages' ) );
		add_action( 'template_redirect', array( Routes::class, 'redirect_legacy_category' ), 10 );
		add_action( 'send_headers', array( self::class, 'send_security_headers' ) );
		add_filter( 'wp_inline_script_attributes', array( self::class, 'add_csp_nonce_attribute' ), 10, 1 );
		add_filter( 'wp_inline_style_attributes', array( self::class, 'add_csp_nonce_attribute' ), 10, 1 );
		add_filter( 'render_block_core/navigation-link', array( self::class, 'filter_navigation_link' ), 10, 2 );

		// Register custom cron interval for invalidation queue processing.
		add_filter( 'cron_schedules', array( self::class, 'register_cron_intervals' ) );
	}

	/**
	 * Register custom cron intervals.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public static function register_cron_intervals( array $schedules ): array {
		$schedules['lel_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Longevity Core)', 'longevity-core' ),
		);
		return $schedules;
	}

	/**
	 * Noindex pages marked as placeholders or in draft status.
	 *
	 * @param array $robots Current robots directives.
	 * @return array Filtered robots directives.
	 */
	public static function filter_noindex_placeholder_pages( array $robots ): array {
		if ( ! is_singular( 'page' ) ) {
			return $robots;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $robots;
		}

		if ( 'draft' === $post->post_status || '1' === get_post_meta( $post->ID, '_longevity_noindex', true ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Filter navigation-link block output to suppress links to non-public routes.
	 *
	 * Checks whether the navigation link URL corresponds to a registered route.
	 * If the route is not public (draft, noindex placeholder, or missing), the
	 * link is removed from the rendered output. External and editorial links
	 * that are not registered routes pass through unchanged.
	 *
	 * @param string $block_content The block content.
	 * @param array  $block         The block data.
	 * @return string Filtered block content.
	 */
	public static function filter_navigation_link( string $block_content, array $block ): string {
		if ( empty( $block['attrs']['url'] ) ) {
			return $block_content;
		}

		$url = $block['attrs']['url'];

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( null === $path ) {
			return $block_content;
		}

		$route_key = Routes::route_key_for_path( $path );
		if ( null === $route_key ) {
			return $block_content;
		}

		if ( Routes::is_public_page( $route_key ) ) {
			if ( 'reviews' === $route_key && ! Rankings::has_public_ranking_inventory() ) {
				return '';
			}
			$public_url = Routes::public_page_url( $route_key );
			if ( null !== $public_url && $public_url !== $url ) {
				$block_content = str_replace( esc_url( $url ), esc_url( $public_url ), $block_content );
			}
			return $block_content;
		}

		if ( Routes::is_public_category( $route_key ) ) {
			return $block_content;
		}

		return '';
	}

	/**
	 * Send conservative application headers. Infrastructure may add stricter headers.
	 */
	public static function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-XSS-Protection: 0' );

		$csp = self::content_security_policy();
		if ( $csp ) {
			$enforce = self::csp_enforce_mode();
			if ( $enforce ) {
				header( 'Content-Security-Policy: ' . $csp );
			} else {
				header( 'Content-Security-Policy-Report-Only: ' . $csp );
			}
		}
	}

	/** Whether CSP should be enforced (production) or report-only (staging/dev). */
	private static function csp_enforce_mode(): bool {
		// Environment variable override.
		$env = getenv( 'LEL_CSP_ENFORCE' );
		if ( false !== $env ) {
			return in_array( strtolower( $env ), array( '1', 'true', 'yes' ), true );
		}
		// Constant override.
		if ( defined( 'LEL_CSP_ENFORCE' ) ) {
			return (bool) LEL_CSP_ENFORCE;
		}
		// Default: enforce only in production environment type.
		return function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type();
	}

	/** Attach the per-request CSP nonce to inline script/style tag attributes. */
	public static function add_csp_nonce_attribute( array $attributes ): array {
		$attributes['nonce'] = self::csp_nonce();
		return $attributes;
	}

	/** Build a Content Security Policy with nonce-based script/style allowance. */
	public static function content_security_policy(): string {
		$nonce = self::csp_nonce();
		$report_uri = rest_url( 'longevity/v1/csp-report' );

		$directives = array(
			"default-src 'self'",
			"script-src 'self' 'nonce-{$nonce}'",
			"style-src 'self' 'nonce-{$nonce}'",
			"img-src 'self' data: https:",
			"font-src 'self' data:",
			"connect-src 'self'",
			"frame-ancestors 'none'",
			"base-uri 'self'",
			"form-action 'self'",
			"report-uri {$report_uri}",
		);
		return implode( '; ', $directives );
	}

	/** Per-request CSP nonce (generated once, reused within the request). */
	public static function csp_nonce(): string {
		static $nonce = null;
		if ( null === $nonce ) {
			$nonce = base64_encode( random_bytes( 16 ) );
		}
		return $nonce;
	}
}
