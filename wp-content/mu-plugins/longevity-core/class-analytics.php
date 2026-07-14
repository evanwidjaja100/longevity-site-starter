<?php
/**
 * Privacy-safe first-party analytics event layer.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Exposes a vendor-neutral event queue. */
final class Analytics {
	/** Register hooks. */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** Enqueue the small event collector. */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script( 'longevity-analytics', LONGEVITY_CORE_URL . 'assets/analytics.js', array(), LONGEVITY_CORE_VERSION, true );
		$config = array(
			'contentId'    => is_singular() ? (string) get_queried_object_id() : '',
			'contentGroup' => is_singular() ? sanitize_key( (string) get_post_type() ) : 'archive',
			'allowedEvents'=> array( 'newsletter_signup', 'affiliate_click', 'outbound_citation_click', 'lead_magnet_download', 'review_method_open', 'evidence_summary_open', 'correction_submit', 'comparison_filter_use', 'methodology_download', 'test_data_download' ),
		);
		wp_add_inline_script( 'longevity-analytics', 'window.longevityAnalyticsConfig=' . wp_json_encode( $config ) . ';', 'before' );
	}
}
