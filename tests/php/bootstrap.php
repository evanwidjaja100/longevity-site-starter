<?php
/** PHPUnit bootstrap with minimal WordPress stubs for pure service tests. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'LONGEVITY_CORE_PATH', dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/longevity-core/' );
define( 'LONGEVITY_CORE_VERSION', '3.0.0' );
define( 'LONGEVITY_CORE_URL', 'http://example.com/wp-content/mu-plugins/longevity-core/' );

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
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post ): ?string {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['lel_test_page_statuses'][ $id ] ?? null;
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
require_once LONGEVITY_CORE_PATH . 'class-seo.php';
require_once LONGEVITY_CORE_PATH . 'class-review-methodology.php';
require_once LONGEVITY_CORE_PATH . 'class-meta-registry.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-gates.php';
require_once LONGEVITY_CORE_PATH . 'class-rankings.php';
require_once LONGEVITY_CORE_PATH . 'class-public-components.php';
require_once LONGEVITY_CORE_PATH . 'class-affiliate-registry.php';
require_once LONGEVITY_CORE_PATH . 'class-admin-ui.php';
require_once LONGEVITY_CORE_PATH . 'class-content-discovery.php';
require_once LONGEVITY_CORE_PATH . 'class-rest-api.php';
require_once LONGEVITY_CORE_PATH . 'class-corrections.php';

// --- Additional WP function stubs for test files ---

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return $url;
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = '' ): string {
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_unique_id' ) ) {
	function wp_unique_id( string $prefix = '' ): string {
		static $id = 0;
		return $prefix . ++$id;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( ...$args ): void {
		$GLOBALS['lel_test_enqueued_scripts'][] = $args;
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string {
		return 'test_nonce_' . $action;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'http://example.com/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array() ): void {
		$GLOBALS['lel_test_rest_routes'][] = compact( 'namespace', 'route', 'args' );
	}
}
if ( ! function_exists( 'gmdate' ) ) {
	function gmdate( string $format, ?int $timestamp = null ): string {
		$ts = $timestamp ?? time();
		return date( $format, $ts ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		$values = array( 'name' => 'Longevity Evidence Lab', 'description' => 'Evidence-based health guidance' );
		return $values[ $show ] ?? '';
	}
}
if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( string $content, string $tag ): bool {
		return false !== strpos( $content, '[' . $tag );
	}
}
if ( ! function_exists( 'get_shortcode_regex' ) ) {
	function get_shortcode_regex( array $tagnames = array() ): string {
		$tagregexp = ! empty( $tagnames ) ? implode( '|', array_map( 'preg_quote', $tagnames ) ) : '[a-zA-Z_]+';
		return '\\[(\\[?)(' . $tagregexp . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/))?\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?(\\]?)';
	}
}
if ( ! function_exists( 'shortcode_parse_atts' ) ) {
	function shortcode_parse_atts( string $text ): array {
		$atts = array();
		if ( preg_match_all( '/(\w+)\s*=\s*"([^"]*)"/', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$atts[ $match[1] ] = $match[2];
			}
		}
		return $atts;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : ( is_array( $value ) ? array_map( 'wp_unslash', $value ) : $value );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $content ): string {
		return $content;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['lel_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['lel_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$args ): bool {
		$caps = $GLOBALS['lel_test_current_user_caps'] ?? array();
		$check = in_array( $capability, $caps, true );
		if ( ! $check && 'edit_post' === $capability && ! empty( $args ) ) {
			return isset( $GLOBALS['lel_test_editable_posts'] ) && in_array( (int) $args[0], $GLOBALS['lel_test_editable_posts'], true );
		}
		return $check;
	}
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post ): bool {
		return $GLOBALS['lel_test_is_revision'] ?? false;
	}
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post ): bool {
		return $GLOBALS['lel_test_is_autosave'] ?? false;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): bool {
		return $nonce === 'test_nonce_' . $action || $nonce === 'valid_nonce';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, $meta_value ): bool {
		if ( ! isset( $GLOBALS['lel_test_meta'][ $post_id ] ) ) {
			$GLOBALS['lel_test_meta'][ $post_id ] = array();
		}
		$GLOBALS['lel_test_meta'][ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return $GLOBALS['lel_test_is_admin'] ?? false;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals, '.', '' );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$field = '<input type="hidden" id="' . $name . '" name="' . $name . '" value="test_nonce_' . $action . '" />';
		if ( $echo ) {
			echo $field;
		}
		return $field;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = '' ): string {
		unset( $domain );
		return 1 === $number ? $single : $plural;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post ) {
		if ( is_object( $post ) ) {
			return $post;
		}
		$id = (int) $post;
		return $GLOBALS['lel_test_posts'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, $post ): string {
		$post_obj = get_post( $post );
		if ( ! $post_obj || ! isset( $post_obj->$field ) ) {
			return '';
		}
		return (string) $post_obj->$field;
	}
}

// --- Minimal WP class stubs ---

if ( ! defined( 'PHP_URL_HOST' ) ) {
	define( 'PHP_URL_HOST', 1 );
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		$parts = parse_url( $url );
		if ( -1 !== $component ) {
			return $parts[ $component ] ?? null;
		}
		return $parts;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		unset( $args );
		return array();
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $query_vars = array();
		public bool $_is_main_query = true;
		public bool $_is_search = false;
		public bool $_is_category = false;
		public bool $_is_tag = false;
		public bool $_is_author = false;
		public bool $_is_home = false;

		public function is_main_query(): bool { return $this->_is_main_query; }
		public function is_search(): bool { return $this->_is_search; }
		public function is_category(): bool { return $this->_is_category; }
		public function is_tag(): bool { return $this->_is_tag; }
		public function is_author(): bool { return $this->_is_author; }
		public function is_home(): bool { return $this->_is_home; }
		public function set( string $key, $value ): void { $this->query_vars[ $key ] = $value; }
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_content = '';
		public string $post_author = '0';
		public string $post_status = 'draft';
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private $status;
		private array $headers = array();
		public function __construct( $data = null, int $status = 200 ) {
			$this->data = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function header( string $key, string $value ): void { $this->headers[ $key ] = $value; }
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE = 'GET';
		const CREATABLE = 'POST';
		const EDITABLE = 'PUT';
		const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = array();
		public string $method = 'GET';
		public string $route = '';
		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route = $route;
		}
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}
		public function offsetGet( $offset ) {
			return $this->params[ $offset ] ?? null;
		}
		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}
	}
}
