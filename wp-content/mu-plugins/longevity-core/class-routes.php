<?php
/**
 * Canonical route and taxonomy registry.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Central registry for canonical page routes, category terms, and URL resolution. */
final class Routes {

	/** @var array<string, array> Canonical page definitions. */
	private static array $page_definitions = array();

	/** @var array<string, array> Canonical category definitions. */
	private static array $category_definitions = array();

	/** @var array<string, int|null> Request-cached page IDs. */
	private static array $page_id_cache = array();

	/** @var array<string, int|null> Request-cached category term IDs. */
	private static array $category_id_cache = array();

	/** @var array<string, string|null> Request-cached URLs. */
	private static array $url_cache = array();

	/**
	 * Register the default route definitions.
	 */
	public static function init(): void {
		self::$page_definitions = (array) apply_filters(
			'longevity_route_page_definitions',
			array(
				'home'                 => array(
					'slug' => '',
					'name' => 'Home',
				),
				'start_here'           => array(
					'slug' => 'start-here',
					'name' => 'Start Here',
				),
				'guides'               => array(
					'slug' => 'guides',
					'name' => 'Guides',
				),
				'topics'               => array(
					'slug' => 'topics',
					'name' => 'Topics',
				),
				'reviews'              => array(
					'slug' => 'reviews',
					'name' => 'Consumer Lab',
				),
				'evidence_methodology' => array(
					'slug' => 'evidence-methodology',
					'name' => 'Evidence Methodology',
				),
				'testing_methodology'  => array(
					'slug' => 'testing-methodology',
					'name' => 'Testing Methodology',
				),
				'editorial_policy'     => array(
					'slug' => 'editorial-policy',
					'name' => 'Editorial Policy',
				),
				'corrections'          => array(
					'slug' => 'corrections',
					'name' => 'Corrections',
				),
				'affiliate_disclosure' => array(
					'slug' => 'affiliate-disclosure',
					'name' => 'Affiliate Disclosure',
				),
				'medical_disclaimer'   => array(
					'slug' => 'medical-disclaimer',
					'name' => 'Medical Disclaimer',
				),
				'about'                => array(
					'slug' => 'about',
					'name' => 'About',
				),
				'contact'              => array(
					'slug' => 'contact',
					'name' => 'Contact',
				),
				'privacy'              => array(
					'slug' => 'privacy',
					'name' => 'Privacy',
				),
				'terms'                => array(
					'slug' => 'terms',
					'name' => 'Terms',
				),
			)
		);

		self::$category_definitions = (array) apply_filters(
			'longevity_route_category_definitions',
			array(
				'evidence'     => array(
					'slug'       => 'evidence-literacy',
					'name'       => 'Evidence Literacy',
					'legacy_slug' => 'evidence-literacy',
				),
				'sleep'        => array(
					'slug'        => 'sleep',
					'name'        => 'Sleep and Circadian Health',
					'legacy_slug' => 'sleep-and-circadian-health',
				),
				'movement'     => array(
					'slug'        => 'movement',
					'name'        => 'Movement and Physical Capacity',
					'legacy_slug' => 'movement-and-physical-capacity',
				),
				'nutrition'    => array(
					'slug'        => 'nutrition',
					'name'        => 'Nutrition and Healthy Aging',
					'legacy_slug' => 'nutrition-and-healthy-aging',
				),
				'wearables'    => array(
					'slug'        => 'wearables',
					'name'        => 'Wearables and Consumer Measurement',
					'legacy_slug' => 'wearables-and-consumer-measurement',
				),
				'supplements'  => array(
					'slug'        => 'supplements',
					'name'        => 'Supplements and High-Uncertainty Interventions',
					'legacy_slug' => 'supplements-and-high-uncertainty-interventions',
				),
				'consumer_lab' => array(
					'slug' => 'consumer-lab',
					'name' => 'Consumer Lab',
				),
			)
		);
	}

	/**
	 * Return all route definitions.
	 *
	 * @return array{ pages: array, categories: array }
	 */
	public static function definitions(): array {
		if ( empty( self::$page_definitions ) ) {
			self::init();
		}
		return array(
			'pages'      => self::$page_definitions,
			'categories' => self::$category_definitions,
		);
	}

	/**
	 * Return a page ID for a canonical key.
	 *
	 * @param string $key Page key (e.g. 'start_here', 'guides').
	 * @return int|null Page ID or null if not found.
	 */
	public static function page_id( string $key ): ?int {
		if ( empty( self::$page_definitions ) ) {
			self::init();
		}
		if ( ! isset( self::$page_definitions[ $key ] ) ) {
			return null;
		}
		if ( 'home' === $key ) {
			return (int) get_option( 'page_on_front' ) ?: null;
		}
		if ( array_key_exists( $key, self::$page_id_cache ) ) {
			return self::$page_id_cache[ $key ];
		}
		$slug = self::$page_definitions[ $key ]['slug'];
		if ( empty( $slug ) ) {
			self::$page_id_cache[ $key ] = null;
			return null;
		}
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		self::$page_id_cache[ $key ] = ( $page && isset( $page->ID ) ) ? (int) $page->ID : null;
		return self::$page_id_cache[ $key ];
	}

	/**
	 * Return a page URL for a canonical key.
	 *
	 * @param string $key Page key.
	 * @return string|null URL or null if page not found.
	 */
	public static function page_url( string $key ): ?string {
		if ( 'home' === $key ) {
			return home_url( '/' );
		}
		if ( array_key_exists( $key, self::$url_cache ) ) {
			return self::$url_cache[ $key ];
		}
		$id = self::page_id( $key );
		if ( null === $id ) {
			self::$url_cache[ $key ] = null;
			return null;
		}
		$url = get_permalink( $id );
		self::$url_cache[ $key ] = is_string( $url ) ? $url : null;
		return self::$url_cache[ $key ];
	}

	/**
	 * Return a category term ID for a canonical key.
	 *
	 * @param string $key Category key (e.g. 'sleep', 'nutrition').
	 * @return int|null Term ID or null if not found.
	 */
	public static function category_id( string $key ): ?int {
		if ( empty( self::$category_definitions ) ) {
			self::init();
		}
		if ( ! isset( self::$category_definitions[ $key ] ) ) {
			return null;
		}
		if ( array_key_exists( $key, self::$category_id_cache ) ) {
			return self::$category_id_cache[ $key ];
		}
		$slug = self::$category_definitions[ $key ]['slug'];
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( ! ( $term && isset( $term->term_id ) ) ) {
			$legacy = self::$category_definitions[ $key ]['legacy_slug'] ?? null;
			if ( $legacy ) {
				$term = get_term_by( 'slug', $legacy, 'category' );
			}
		}
		self::$category_id_cache[ $key ] = ( $term && isset( $term->term_id ) ) ? (int) $term->term_id : null;
		return self::$category_id_cache[ $key ];
	}

	/**
	 * Return a category URL for a canonical key.
	 *
	 * @param string $key Category key.
	 * @return string|null URL or null if term not found.
	 */
	public static function category_url( string $key ): ?string {
		if ( array_key_exists( $key, self::$url_cache ) ) {
			return self::$url_cache[ $key ];
		}
		$id = self::category_id( $key );
		if ( null === $id ) {
			self::$url_cache[ $key ] = null;
			return null;
		}
		$url = get_term_link( $id, 'category' );
		self::$url_cache[ $key ] = is_string( $url ) ? $url : null;
		return self::$url_cache[ $key ];
	}

	/**
	 * Return the review post type archive URL.
	 *
	 * @return string|null URL or null if not available.
	 */
	public static function review_archive_url(): ?string {
		$url = get_post_type_archive_link( 'review' );
		return is_string( $url ) ? $url : null;
	}

	/**
	 * Return the search page URL.
	 *
	 * @return string
	 */
	public static function search_url(): string {
		return home_url( '/' ) . '?s=';
	}

	/**
	 * Compare two route keys.
	 *
	 * @param string $a First route key.
	 * @param string $b Second route key.
	 * @return bool True if both resolve to the same URL.
	 */
	public static function is_same_route( string $a, string $b ): bool {
		$url_a = self::page_url( $a ) ?? self::category_url( $a );
		$url_b = self::page_url( $b ) ?? self::category_url( $b );
		if ( null === $url_a || null === $url_b ) {
			return false;
		}
		return untrailingslashit( $url_a ) === untrailingslashit( $url_b );
	}
}
