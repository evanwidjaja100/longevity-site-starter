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

	/** Enqueue the small event collector and print config as a data block. */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script( 'longevity-analytics', LONGEVITY_CORE_URL . 'assets/analytics.js', array(), LONGEVITY_CORE_VERSION, true );
		add_action( 'wp_footer', array( self::class, 'print_config' ), 0 );
	}

	/** Print analytics configuration as a JSON data block (safe for CSP). */
	public static function print_config(): void {
		$config = array(
			'contentId'    => is_singular() ? (string) get_queried_object_id() : '',
			'contentGroup' => is_singular() ? sanitize_key( (string) get_post_type() ) : 'archive',
			'eventSchemas' => self::event_schemas(),
		);
		echo '<script id="longevity-analytics-config" type="application/json">' . wp_json_encode( $config ) . '</script>' . "\n";
	}

	/** Return the only public parameters accepted for each event. */
	public static function event_schemas(): array {
		return array(
			'newsletter_signup'       => array( 'placement', 'content_group' ),
			'affiliate_click'          => array( 'merchant', 'content_id', 'placement' ),
			'outbound_citation_click' => array( 'destination_domain', 'content_id' ),
			'lead_magnet_download'     => array( 'asset_id', 'placement' ),
			'review_method_open'       => array( 'content_id', 'product_category' ),
			'evidence_summary_open'    => array( 'content_id' ),
			'correction_submit'        => array( 'content_id' ),
			'comparison_filter_use'    => array( 'content_id', 'product_category' ),
			'methodology_download'     => array( 'content_id', 'placement' ),
			'test_data_download'       => array( 'content_id', 'placement' ),
			'ranking_sort'            => array( 'category', 'sort' ),
			'ranking_filter'          => array( 'category', 'filter_name' ),
			'ranking_report_open'     => array( 'content_id', 'category', 'placement' ),
			'outbound_click'          => array( 'content_id', 'placement' ),
			'search_open'             => array( 'placement' ),
		);
	}
}
