<?php
/**
 * Longevity Core bootstrap.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

$longevity_core_files = array(
	'class-gate-result.php',
	'class-routes.php',
	'class-content-types.php',
	'class-roles.php',
	'class-meta-registry.php',
	'class-claims.php',
	'class-affiliate-registry.php',
	'class-review-methodology.php',
	'class-rankings.php',
	'class-corrections.php',
	'class-publication-gates.php',
	'class-review-workflow.php',
	'class-migrations.php',
	'class-freshness.php',
	'class-content-discovery.php',
	'class-public-components.php',
	'class-blocks.php',
	'class-admin-assets.php',
	'class-admin-ui.php',
	'class-shortcodes.php',
	'class-schema.php',
	'class-analytics.php',
	'class-rest-api.php',
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
		Roles::init();
		Meta_Registry::init();
		Claims::init();
		Affiliate_Registry::init();
		Review_Methodology::init();
		Rankings::init();
		Corrections::init();
		Publication_Gates::init();
		Review_Workflow::init();
		Migrations::init();
		Freshness::init();
		Content_Discovery::init();
		Public_Components::init();
		Blocks::init();
		Admin_Assets::init();
		Admin_UI::init();
		Shortcodes::init();
		Schema::init();
		Analytics::init();
		Rest_API::init();
		CLI::init();

		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'wp_robots', array( self::class, 'filter_noindex_placeholder_pages' ) );
		add_action( 'send_headers', array( self::class, 'send_security_headers' ) );
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
	}
}
