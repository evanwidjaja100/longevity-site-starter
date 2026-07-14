<?php
/**
 * Theme bootstrap.
 *
 * @package LongevityStarter
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'longevity-starter', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
	}
);

add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'editor-styles' );
		add_editor_style( 'style.css' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'custom-logo' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	}
);

/** Include public review content in reader-facing main queries. */
add_action(
	'pre_get_posts',
	static function ( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->is_search() || $query->is_category() || $query->is_tag() || $query->is_author() || $query->is_home() ) {
			$query->set( 'post_type', array( 'post', 'review' ) );
		}
	}
);

/** Add semantic body classes for trust-oriented layouts. */
add_filter(
	'body_class',
	static function ( array $classes ): array {
		if ( is_singular( 'review' ) ) {
			$classes[] = 'is-product-review';
		}
		if ( is_singular( array( 'post', 'review' ) ) ) {
			$classes[] = 'is-evidence-article';
		}
		return $classes;
	}
);
