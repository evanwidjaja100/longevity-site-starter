<?php
/** PHPUnit bootstrap with minimal WordPress stubs for pure service tests. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'LONGEVITY_CORE_PATH', dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/longevity-core/' );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? '';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ): string {
		return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : '';
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $string ): string {
		return rtrim( $string, '/\\' );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'http://example.com' . $path;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		if ( 'page_on_front' === $option ) {
			return $GLOBALS['lel_test_page_on_front'] ?? 0;
		}
		if ( 'page_for_posts' === $option ) {
			return 0;
		}
		if ( 'blog_public' === $option ) {
			return 0;
		}
		return $default;
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $slug, string $output = OBJECT, string $post_type = 'page' ) {
		unset( $output, $post_type );
		return $GLOBALS['lel_test_pages_by_slug'][ $slug ] ?? null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ): string {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['lel_test_permalinks'][ $id ] ?? 'http://example.com/?p=' . $id;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( string $field, $value, string $taxonomy = 'category' ) {
		unset( $taxonomy );
		if ( 'slug' === $field && isset( $GLOBALS['lel_test_terms_by_slug'][ $value ] ) ) {
			return $GLOBALS['lel_test_terms_by_slug'][ $value ];
		}
		if ( 'id' === $field && isset( $GLOBALS['lel_test_terms_by_id'][ $value ] ) ) {
			return $GLOBALS['lel_test_terms_by_id'][ $value ];
		}
		return null;
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term, string $taxonomy = '' ): string {
		unset( $taxonomy );
		$id = is_object( $term ) ? (int) $term->term_id : (int) $term;
		return $GLOBALS['lel_test_term_links'][ $id ] ?? 'http://example.com/category/' . $id;
	}
}
if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( string $post_type ): string {
		if ( 'review' === $post_type ) {
			return 'http://example.com/reviews/';
		}
		return 'http://example.com/' . $post_type . '/';
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		unset( $single );
		return $GLOBALS['lel_test_meta'][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ): string {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return (string) ( $GLOBALS['lel_test_titles'][ $post_id ] ?? '' );
	}
}

require_once LONGEVITY_CORE_PATH . 'class-gate-result.php';
require_once LONGEVITY_CORE_PATH . 'class-routes.php';
require_once LONGEVITY_CORE_PATH . 'class-review-methodology.php';
require_once LONGEVITY_CORE_PATH . 'class-meta-registry.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-gates.php';
require_once LONGEVITY_CORE_PATH . 'class-rankings.php';
