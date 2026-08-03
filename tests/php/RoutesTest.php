<?php
/**
 * Tests for the canonical Route registry.
 *
 * @package LongevityCore
 */

use Longevity\Core\Routes;

require_once __DIR__ . '/bootstrap.php';

/**
 * Reset globals between test scenarios.
 *
 * @param int|null   $page_on_front  Front page ID.
 * @param array|null $pages_by_slug  Mock pages.
 * @param array|null $permalinks     Mock permalink map.
 * @param array|null $terms_by_slug  Mock terms.
 * @param array|null $term_links     Mock term link map.
 * @param array|null $page_statuses  Mock post statuses.
 * @param array|null $meta           Mock post meta.
 */
function reset_routes_globals( $page_on_front = null, $pages_by_slug = null, $permalinks = null, $terms_by_slug = null, $term_links = null, $page_statuses = null, $meta = null ): void {
	$GLOBALS['lel_test_page_on_front'] = $page_on_front ?? 0;
	$GLOBALS['lel_test_pages_by_slug'] = $pages_by_slug ?? array();
	$GLOBALS['lel_test_permalinks']    = $permalinks ?? array();
	$GLOBALS['lel_test_terms_by_slug'] = $terms_by_slug ?? array();
	$GLOBALS['lel_test_terms_by_id']   = array();
	$GLOBALS['lel_test_term_links']    = $term_links ?? array();
	$GLOBALS['lel_test_page_statuses'] = $page_statuses ?? array();
	$GLOBALS['lel_test_meta']          = $meta ?? array();
}

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

// --- Setup mock data ---

reset_routes_globals(
	42, // page_on_front
	array( // pages_by_slug
		'start-here'           => (object) array( 'ID' => 10, 'post_name' => 'start-here', 'post_type' => 'page' ),
		'guides'               => (object) array( 'ID' => 20, 'post_name' => 'guides', 'post_type' => 'page' ),
		'topics'               => (object) array( 'ID' => 30, 'post_name' => 'topics', 'post_type' => 'page' ),
		'about'                => (object) array( 'ID' => 50, 'post_name' => 'about', 'post_type' => 'page' ),
		'evidence-methodology' => (object) array( 'ID' => 60, 'post_name' => 'evidence-methodology', 'post_type' => 'page' ),
	),
	array( // permalinks
		10 => 'http://example.com/start-here/',
		20 => 'http://example.com/guides/',
		30 => 'http://example.com/topics/',
		42 => 'http://example.com/',
		50 => 'http://example.com/about/',
		60 => 'http://example.com/evidence-methodology/',
	),
	array( // terms_by_slug
		'sleep'                            => (object) array( 'term_id' => 3, 'slug' => 'sleep', 'name' => 'Sleep and Circadian Health', 'taxonomy' => 'category' ),
		'evidence-literacy'                => (object) array( 'term_id' => 2, 'slug' => 'evidence-literacy', 'name' => 'Evidence Literacy', 'taxonomy' => 'category' ),
		'consumer-lab'                     => (object) array( 'term_id' => 7, 'slug' => 'consumer-lab', 'name' => 'Consumer Lab', 'taxonomy' => 'category' ),
		'sleep-and-circadian-health'       => (object) array( 'term_id' => 3, 'slug' => 'sleep-and-circadian-health', 'name' => 'Sleep and Circadian Health', 'taxonomy' => 'category' ),
		'movement'                         => (object) array( 'term_id' => 4, 'slug' => 'movement', 'name' => 'Movement and Physical Capacity', 'taxonomy' => 'category' ),
		'nutrition'                        => (object) array( 'term_id' => 5, 'slug' => 'nutrition', 'name' => 'Nutrition and Healthy Aging', 'taxonomy' => 'category' ),
	),
	array( // term_links
		2 => 'http://example.com/category/evidence-literacy/',
		3 => 'http://example.com/category/sleep/',
		4 => 'http://example.com/category/movement/',
		5 => 'http://example.com/category/nutrition/',
		7 => 'http://example.com/category/consumer-lab/',
	)
);

Routes::init();

// --- Test 1: All page keys resolve to correct IDs ---

$expected_pages = array(
	'home'                 => 42,
	'start_here'           => 10,
	'guides'               => 20,
	'topics'               => 30,
	'about'                => 50,
	'evidence_methodology' => 60,
	'editorial_policy'     => null,
	'corrections'          => null,
	'affiliate_disclosure' => null,
	'medical_disclaimer'   => null,
	'contact'              => null,
	'privacy'              => null,
	'terms'                => null,
);

foreach ( $expected_pages as $key => $expected_id ) {
	$result = Routes::page_id( $key );
	$assert(
		$result === $expected_id,
		sprintf( 'page_id(%s): expected %s, got %s', $key, var_export( $expected_id, true ), var_export( $result, true ) )
	);
}

// --- Test 2: Unknown page key returns null ---

$assert( null === Routes::page_id( 'nonexistent' ), 'Unknown page key should return null' );

// --- Test 3: Known page keys resolve to correct URLs ---

$expected_page_urls = array(
	'home'       => 'http://example.com/',
	'start_here' => 'http://example.com/start-here/',
	'guides'     => 'http://example.com/guides/',
	'about'      => 'http://example.com/about/',
);

foreach ( $expected_page_urls as $key => $expected_url ) {
	$result = Routes::page_url( $key );
	$assert(
		$result === $expected_url,
		sprintf( 'page_url(%s): expected %s, got %s', $key, $expected_url, var_export( $result, true ) )
	);
}

// --- Test 4: Missing page returns null URL ---

$assert( null === Routes::page_url( 'nonexistent' ), 'Missing page should return null URL' );

// --- Test 5: Category keys resolve to correct IDs ---

$expected_cats = array(
	'evidence'     => 2,
	'sleep'        => 3,
	'movement'     => 4,
	'nutrition'    => 5,
	'consumer_lab' => 7,
	'wearables'    => null,
	'supplements'  => null,
);

foreach ( $expected_cats as $key => $expected_id ) {
	$result = Routes::category_id( $key );
	$assert(
		$result === $expected_id,
		sprintf( 'category_id(%s): expected %s, got %s', $key, var_export( $expected_id, true ), var_export( $result, true ) )
	);
}

// --- Test 6: Unknown category key returns null ---

$assert( null === Routes::category_id( 'bogus' ), 'Unknown category key should return null' );

// --- Test 7: Category URLs resolve correctly ---

$expected_cat_urls = array(
	'sleep'    => 'http://example.com/category/sleep/',
	'movement' => 'http://example.com/category/movement/',
	'nutrition' => 'http://example.com/category/nutrition/',
);

foreach ( $expected_cat_urls as $key => $expected_url ) {
	$result = Routes::category_url( $key );
	$assert(
		$result === $expected_url,
		sprintf( 'category_url(%s): expected %s, got %s', $key, $expected_url, var_export( $result, true ) )
	);
}

// --- Test 8: Missing category returns null URL ---

$assert( null === Routes::category_url( 'wearables' ), 'Missing category should return null URL' );

// --- Test 9: Review archive URL ---

$review_url = Routes::review_archive_url();
$assert( 'http://example.com/reviews/' === $review_url, 'Review archive URL mismatch: ' . var_export( $review_url, true ) );

// --- Test 10: Search URL ---

$search_url = Routes::search_url();
$assert( 'http://example.com/?s=' === $search_url, 'Search URL mismatch: ' . $search_url );

// --- Test 11: Definitions output ---

$defs = Routes::definitions();
$assert( isset( $defs['pages']['home'] ), 'Definitions should contain home page' );
$assert( isset( $defs['categories']['sleep'] ), 'Definitions should contain sleep category' );
$assert( 17 === count( $defs['pages'] ), 'Should have 17 page definitions, got ' . count( $defs['pages'] ) );
$assert( 7 === count( $defs['categories'] ), 'Should have 7 category definitions, got ' . count( $defs['categories'] ) );

$expected_page_keys = array( 'home', 'start_here', 'guides', 'topics', 'reviews', 'evidence_methodology', 'testing_methodology', 'editorial_policy', 'corrections', 'affiliate_disclosure', 'medical_disclaimer', 'about', 'contact', 'privacy', 'terms', 'ai_assist_disclosure', 'source_registry' );
$assert( $expected_page_keys === array_keys( $defs['pages'] ), 'Page definition keys changed unexpectedly — update this test deliberately if intentional. Expected ' . implode( ', ', $expected_page_keys ) . ', got ' . implode( ', ', array_keys( $defs['pages'] ) ) );

// --- Test 12: is_same_route ---

$assert(
	false === Routes::is_same_route( 'start_here', 'home' ),
	'start_here and home should not be the same route after separation'
);

$assert(
	false === Routes::is_same_route( 'nonexistent', 'home' ),
	'Comparing with nonexistent key should return false'
);

// --- Test 13: Legacy slug fallback (supplements not in mock, should be null) ---

$assert( null === Routes::category_id( 'supplements' ), 'supplements category should be null when not in mock data' );

// --- Test 14: page_status returns correct status ---

reset_routes_globals(
	42,
	array(
		'start-here'           => (object) array( 'ID' => 10, 'post_name' => 'start-here', 'post_type' => 'page' ),
		'guides'               => (object) array( 'ID' => 20, 'post_name' => 'guides', 'post_type' => 'page' ),
		'evidence-methodology' => (object) array( 'ID' => 60, 'post_name' => 'evidence-methodology', 'post_type' => 'page' ),
	),
	array(
		10 => 'http://example.com/start-here/',
		20 => 'http://example.com/guides/',
		42 => 'http://example.com/',
		60 => 'http://example.com/evidence-methodology/',
	),
	null,
	null,
	array(
		10 => 'publish',
		20 => 'draft',
		42 => 'publish',
		60 => 'publish',
	),
	array(
		10 => array(),
		60 => array( '_longevity_noindex' => '1' ),
	)
);
Routes::init();

$assert(
	'publish' === Routes::page_status( 'start_here' ),
	'page_status(start_here) should be publish'
);
$assert(
	'draft' === Routes::page_status( 'guides' ),
	'page_status(guides) should be draft'
);
$assert(
	null === Routes::page_status( 'topics' ),
	'page_status(topics) should be null (no page)'
);

// --- Test 15: is_public_page ---

$assert(
	true === Routes::is_public_page( 'start_here' ),
	'is_public_page(start_here) should be true (published, no noindex)'
);
$assert(
	false === Routes::is_public_page( 'guides' ),
	'is_public_page(guides) should be false (draft)'
);
$assert(
	false === Routes::is_public_page( 'evidence_methodology' ),
	'is_public_page(evidence_methodology) should be false (noindex placeholder)'
);
$assert(
	false === Routes::is_public_page( 'topics' ),
	'is_public_page(topics) should be false (no page)'
);

// --- Test 16: public_page_url ---

$assert(
	'http://example.com/start-here/' === Routes::public_page_url( 'start_here' ),
	'public_page_url(start_here) should return URL'
);
$assert(
	null === Routes::public_page_url( 'guides' ),
	'public_page_url(guides) should be null (draft)'
);
$assert(
	null === Routes::public_page_url( 'evidence_methodology' ),
	'public_page_url(evidence_methodology) should be null (noindex)'
);

// --- Test 17: is_public_category ---

$assert(
	true === Routes::is_public_category( 'sleep' ),
	'is_public_category(sleep) should be true (exists)'
);
$assert(
	false === Routes::is_public_category( 'wearables' ),
	'is_public_category(wearables) should be false (no term)'
);

// --- Test 18: route_key_for_path ---

$assert(
	'home' === Routes::route_key_for_path( '/' ),
	'route_key_for_path(/) should be home'
);
$assert(
	'home' === Routes::route_key_for_path( '' ),
	'route_key_for_path(empty) should be home'
);
$assert(
	'start_here' === Routes::route_key_for_path( '/start-here/' ),
	'route_key_for_path(/start-here/) should be start_here'
);
$assert(
	'sleep' === Routes::route_key_for_path( '/category/sleep/' ),
	'route_key_for_path(/category/sleep/) should be sleep'
);
$assert(
	'sleep' === Routes::route_key_for_path( '/category/sleep-and-circadian-health/' ),
	'route_key_for_path(/category/sleep-and-circadian-health/) should resolve legacy to sleep'
);
$assert(
	null === Routes::route_key_for_path( '/unknown/path/' ),
	'route_key_for_path(/unknown/path/) should be null'
);

// Restore globals for remaining tests.
reset_routes_globals(
	42,
	array( // pages_by_slug
		'start-here'           => (object) array( 'ID' => 10, 'post_name' => 'start-here', 'post_type' => 'page' ),
		'guides'               => (object) array( 'ID' => 20, 'post_name' => 'guides', 'post_type' => 'page' ),
		'topics'               => (object) array( 'ID' => 30, 'post_name' => 'topics', 'post_type' => 'page' ),
		'about'                => (object) array( 'ID' => 50, 'post_name' => 'about', 'post_type' => 'page' ),
		'evidence-methodology' => (object) array( 'ID' => 60, 'post_name' => 'evidence-methodology', 'post_type' => 'page' ),
	),
	array( // permalinks
		10 => 'http://example.com/start-here/',
		20 => 'http://example.com/guides/',
		30 => 'http://example.com/topics/',
		42 => 'http://example.com/',
		50 => 'http://example.com/about/',
		60 => 'http://example.com/evidence-methodology/',
	),
	array( // terms_by_slug
		'sleep'                            => (object) array( 'term_id' => 3, 'slug' => 'sleep', 'name' => 'Sleep and Circadian Health', 'taxonomy' => 'category' ),
		'evidence-literacy'                => (object) array( 'term_id' => 2, 'slug' => 'evidence-literacy', 'name' => 'Evidence Literacy', 'taxonomy' => 'category' ),
		'consumer-lab'                     => (object) array( 'term_id' => 7, 'slug' => 'consumer-lab', 'name' => 'Consumer Lab', 'taxonomy' => 'category' ),
		'sleep-and-circadian-health'       => (object) array( 'term_id' => 3, 'slug' => 'sleep-and-circadian-health', 'name' => 'Sleep and Circadian Health', 'taxonomy' => 'category' ),
		'movement'                         => (object) array( 'term_id' => 4, 'slug' => 'movement', 'name' => 'Movement and Physical Capacity', 'taxonomy' => 'category' ),
		'nutrition'                        => (object) array( 'term_id' => 5, 'slug' => 'nutrition', 'name' => 'Nutrition and Healthy Aging', 'taxonomy' => 'category' ),
	),
	array( // term_links
		2 => 'http://example.com/category/evidence-literacy/',
		3 => 'http://example.com/category/sleep/',
		4 => 'http://example.com/category/movement/',
		5 => 'http://example.com/category/nutrition/',
		7 => 'http://example.com/category/consumer-lab/',
	)
);
Routes::init();

// --- Test 19: No admin or preview URLs ---

foreach ( array( 'home', 'start_here', 'about' ) as $key ) {
	$url = Routes::page_url( $key );
	if ( null !== $url ) {
		$assert(
			false === strpos( $url, '/wp-admin/' ),
			'page_url(' . $key . ') should not contain admin path: ' . $url
		);
	}
}

// --- Report ---

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: $failure\n" );
	}
	exit( 1 );
}

echo "Routes tests passed.\n";
