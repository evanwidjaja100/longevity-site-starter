<?php
/**
 * Update the Contact page with the contact form shortcode.
 *
 * Usage: wp eval-file scripts/update-contact-page.php
 *
 * @package LongevityCore
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run via WP-CLI.\n";
	exit( 1 );
}

$today = gmdate( 'Y-m-d' );

$content = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Contact</h1><!-- /wp:heading -->
<!-- wp:heading --><h2 class="wp-block-heading">General inquiries</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>For general questions about the site, our methodology, or content, please use the form below.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Report an error</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>If you believe you have found an error in our content, see our <a href="/corrections/">Corrections page</a> for guidance and use the form below. Please include the page URL and a description of the issue.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">Send us a message</h2><!-- /wp:heading -->
<!-- wp:shortcode -->[longevity_contact_form]<!-- /wp:shortcode -->
<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->
<!-- wp:paragraph --><p><strong>Last reviewed:</strong> ' . esc_html( $today ) . '</p><!-- /wp:paragraph -->';

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$posts = get_posts(
	array(
		'name'           => 'contact',
		'post_type'      => 'page',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	)
);
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$post = ! empty( $posts ) ? $posts[0] : null;
if ( ! $post ) {
	\WP_CLI::error( 'Contact page not found.' );
}

$result = wp_update_post(
	array(
		'ID'           => $post->ID,
		'post_content' => $content,
	),
	true
);
if ( is_wp_error( $result ) ) {
	\WP_CLI::error( 'Failed to update contact page: ' . $result->get_error_message() );
}

\WP_CLI::success( 'Contact page updated with form shortcode.' );
