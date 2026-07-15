<?php
/** PHPUnit bootstrap with minimal WordPress stubs for pure service tests. */

define( 'ABSPATH', __DIR__ . '/' );
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
require_once LONGEVITY_CORE_PATH . 'class-review-methodology.php';
require_once LONGEVITY_CORE_PATH . 'class-meta-registry.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-gates.php';
require_once LONGEVITY_CORE_PATH . 'class-rankings.php';
