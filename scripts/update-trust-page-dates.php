<?php
/**
 * Update trust page review dates.
 *
 * Sets last_material_update and next_content_review_date on all trust pages.
 *
 * Usage: wp eval-file scripts/update-trust-page-dates.php
 *
 * @package LongevityCore
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run via WP-CLI.\n";
	exit( 1 );
}

$today = gmdate( 'Y-m-d' );
$next_year = gmdate( 'Y-m-d', strtotime( '+12 months' ) );

$pages = array(
	'about',
	'editorial-policy',
	'evidence-methodology',
	'testing-methodology',
	'medical-disclaimer',
	'affiliate-disclosure',
	'corrections',
	'privacy',
	'terms',
	'contact',
	'ai-assisted-work-disclosure',
	'source-registry',
);

$updated = 0;
foreach ( $pages as $slug ) {
	$posts = get_posts( array( 'name' => $slug, 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 1, 'no_found_rows' => true ) );
	$post  = ! empty( $posts ) ? $posts[0] : null;
	if ( ! $post ) {
		\WP_CLI::warning( "Page not found: /{$slug}/" );
		continue;
	}
	update_post_meta( $post->ID, 'last_material_update', $today );
	update_post_meta( $post->ID, 'next_content_review_date', $next_year );
	\WP_CLI::line( "Updated dates for /{$slug}/ (ID {$post->ID})" );
	++$updated;
}

// Set global policy review date option.
update_option( 'lel_policy_review_date', $today );

\WP_CLI::success( sprintf( 'Done: %d page(s) updated.', $updated ) );
