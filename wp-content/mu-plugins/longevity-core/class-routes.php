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

	/** @var array<string, string|null> Request-cached page statuses. */
	private static array $status_cache = array();

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
				'ai_assist_disclosure' => array(
					'slug' => 'ai-assisted-work-disclosure',
					'name' => 'AI-Assisted Work Disclosure',
				),
				'source_registry'      => array(
					'slug' => 'source-registry',
					'name' => 'Source Registry',
				),
			)
		);

		self::$category_definitions = (array) apply_filters(
			'longevity_route_category_definitions',
			array(
			'evidence'     => array(
				'slug'         => 'evidence-literacy',
				'name'         => 'Evidence Literacy',
				'legacy_slugs' => array(),
			),
			'sleep'        => array(
				'slug'         => 'sleep',
				'name'         => 'Sleep and Circadian Health',
				'legacy_slugs' => array( 'sleep-and-circadian-health' ),
			),
			'movement'     => array(
				'slug'         => 'movement',
				'name'         => 'Movement and Physical Capacity',
				'legacy_slugs' => array( 'movement-and-physical-capacity' ),
			),
			'nutrition'    => array(
				'slug'         => 'nutrition',
				'name'         => 'Nutrition and Healthy Aging',
				'legacy_slugs' => array( 'nutrition-and-healthy-aging' ),
			),
			'wearables'    => array(
				'slug'         => 'wearables',
				'name'         => 'Wearables and Consumer Measurement',
				'legacy_slugs' => array( 'wearables-and-consumer-measurement' ),
			),
			'supplements'  => array(
				'slug'         => 'supplements',
				'name'         => 'Supplements and High-Uncertainty Interventions',
				'legacy_slugs' => array( 'supplements-and-high-uncertainty-interventions' ),
			),
				'consumer_lab' => array(
					'slug'         => 'consumer-lab',
					'name'         => 'Consumer Lab',
					'legacy_slugs' => array(),
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
	 * Return the canonical slug for a category key.
	 *
	 * @param string $key Category key (e.g. 'sleep', 'nutrition').
	 * @return string Canonical slug or empty string if key not found.
	 */
	public static function canonical_slug( string $key ): string {
		if ( empty( self::$category_definitions ) ) {
			self::init();
		}
		return self::$category_definitions[ $key ]['slug'] ?? '';
	}

	/**
	 * Return the legacy slugs for a category key.
	 *
	 * @param string $key Category key (e.g. 'sleep', 'nutrition').
	 * @return array<int, string> Legacy slugs.
	 */
	public static function legacy_slugs( string $key ): array {
		if ( empty( self::$category_definitions ) ) {
			self::init();
		}
		return self::$category_definitions[ $key ]['legacy_slugs'] ?? array();
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
		$slug = self::canonical_slug( $key );
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( ! ( $term && isset( $term->term_id ) ) ) {
			foreach ( self::legacy_slugs( $key ) as $legacy ) {
				$term = get_term_by( 'slug', $legacy, 'category' );
				if ( $term && isset( $term->term_id ) ) {
					break;
				}
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

	/**
	 * Return the canonical category key for a term ID.
	 *
	 * @param int $term_id Category term ID.
	 * @return string|null Key or null if not found.
	 */
	public static function category_key( int $term_id ): ?string {
		if ( empty( self::$category_definitions ) ) {
			self::init();
		}
		foreach ( self::$category_definitions as $key => $def ) {
			$id = self::category_id( $key );
			if ( $id === $term_id ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Return the canonical URL for a category slug, looking up both canonical
	 * and legacy slugs.
	 *
	 * @param string $slug The slug to resolve.
	 * @return string|null Canonical URL or null if slug is not a known category.
	 */
	public static function canonical_url_for_slug( string $slug ): ?string {
		if ( empty( self::$category_definitions ) ) {
			self::init();
		}
		foreach ( self::$category_definitions as $key => $def ) {
			if ( $def['slug'] === $slug ) {
				return self::category_url( $key );
			}
			foreach ( $def['legacy_slugs'] as $legacy ) {
				if ( $legacy === $slug ) {
					return self::category_url( $key );
				}
			}
		}
		return null;
	}

	/**
	 * Return the post status for a page route key.
	 *
	 * @param string $key Page key.
	 * @return string|null Post status or null if page not found.
	 */
	public static function page_status( string $key ): ?string {
		if ( array_key_exists( $key, self::$status_cache ) ) {
			return self::$status_cache[ $key ];
		}
		$id = self::page_id( $key );
		if ( null === $id ) {
			self::$status_cache[ $key ] = null;
			return null;
		}
		$status = get_post_status( $id );
		self::$status_cache[ $key ] = is_string( $status ) ? $status : null;
		return self::$status_cache[ $key ];
	}

	/**
	 * Check whether a page route is safe to link publicly.
	 *
	 * Requires an existing page with publish status, no _longevity_noindex
	 * placeholder flag, and a valid permalink.
	 *
	 * @param string $key Page key.
	 * @return bool True if the page is public.
	 */
	public static function is_public_page( string $key ): bool {
		if ( 'publish' !== self::page_status( $key ) ) {
			return false;
		}
		$id = self::page_id( $key );
		if ( null === $id ) {
			return false;
		}
		if ( '1' === get_post_meta( $id, '_longevity_noindex', true ) ) {
			return false;
		}
		$url = self::page_url( $key );
		return null !== $url && '' !== $url;
	}

	/**
	 * Return the public URL for a page route only when it is safe to link.
	 *
	 * @param string $key Page key.
	 * @return string|null URL or null if not public.
	 */
	public static function public_page_url( string $key ): ?string {
		return self::is_public_page( $key ) ? self::page_url( $key ) : null;
	}

	/**
	 * Check whether a category route exists and has published content.
	 *
	 * @param string $key Category key.
	 * @return bool True if the category exists.
	 */
	public static function is_public_category( string $key ): bool {
		$id = self::category_id( $key );
		return null !== $id;
	}

	/**
	 * Resolve a URL path to a route key.
	 *
	 * Matches page slugs and category paths. Returns the first matching key
	 * or null if no registered route matches.
	 *
	 * @param string $path The URL path to resolve (e.g. '/start-here/' or '/category/sleep/').
	 * @return string|null Route key or null.
	 */
	public static function route_key_for_path( string $path ): ?string {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return 'home';
		}

		if ( empty( self::$page_definitions ) ) {
			self::init();
		}
		foreach ( self::$page_definitions as $key => $def ) {
			if ( $def['slug'] === $path ) {
				return $key;
			}
		}

		$cat_prefix = 'category/';
		if ( str_starts_with( $path, $cat_prefix ) ) {
			$cat_slug = substr( $path, strlen( $cat_prefix ) );
			foreach ( self::$category_definitions as $key => $def ) {
				if ( $def['slug'] === $cat_slug ) {
					return $key;
				}
				foreach ( $def['legacy_slugs'] as $legacy ) {
					if ( $legacy === $cat_slug ) {
						return $key;
					}
				}
			}
		}

		if ( str_starts_with( $path, '?' ) || str_starts_with( $path, '?s=' ) ) {
			return 'search';
		}

		return null;
	}

	/**
	 * Whether a slug is a known legacy (non-canonical) category slug.
	 *
	 * @param string $slug The slug to check.
	 * @return bool True if the slug is a legacy slug that should redirect.
	 */
	public static function is_legacy_slug( string $slug ): bool {
		if ( empty( self::$category_definitions ) ) {
			self::init();
		}
		foreach ( self::$category_definitions as $def ) {
			if ( in_array( $slug, $def['legacy_slugs'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redirect legacy category paths to their canonical short-slug URLs.
	 *
	 * Runs on template_redirect. Only redirects known legacy category slugs;
	 * canonical slugs are never redirected. Only allowlisted query parameters
	 * explicitly present in the request are preserved. Tracking and arbitrary
	 * parameters are stripped by default.
	 */
	public static function redirect_legacy_category(): void {
		if ( ! is_category() ) {
			return;
		}
		$term = get_queried_object();
		if ( ! ( $term instanceof \WP_Term ) ) {
			return;
		}
		$current_slug = $term->slug;

		// Never redirect canonical slugs — only actual legacy slugs.
		if ( ! self::is_legacy_slug( $current_slug ) ) {
			return;
		}

		$canonical = self::canonical_url_for_slug( $current_slug );
		if ( null === $canonical ) {
			return;
		}

		// Preserve only allowlisted ranking/pagination params explicitly in the request.
		$safe_params = array( 'paged', 'ranking_sort', 'confidence', 'subscription' );
		$preserved   = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect preservation.
		foreach ( $safe_params as $p ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET[ $p ] ) || '' === $_GET[ $p ] ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$val = sanitize_text_field( wp_unslash( $_GET[ $p ] ) );
			// Do not append default/meaningless values.
			if ( 'paged' === $p && (int) $val <= 1 ) {
				continue;
			}
			if ( 'ranking_sort' === $p && ! in_array( $val, array( 'score', 'confidence', 'updated', 'title' ), true ) ) {
				continue;
			}
			$preserved[ $p ] = $val;
		}

		$redirect_url = empty( $preserved )
			? $canonical
			: add_query_arg( $preserved, $canonical );

		// Prevent redirect loops.
		$current_url = home_url( add_query_arg( array() ) );
		if ( untrailingslashit( $current_url ) === untrailingslashit( $redirect_url ) ) {
			return;
		}
		wp_safe_redirect( $redirect_url, 301 );
		exit;
	}
}
