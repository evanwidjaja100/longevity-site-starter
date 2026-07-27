<?php
/** PHPUnit bootstrap with minimal WordPress stubs for pure service tests. */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
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
		if ( 'lel_data_version' === $option ) {
			return 10;
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

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	/** @var \wpdb $wpdb */
	$GLOBALS['wpdb'] = new class {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		private array $rows = array(
			'wp_lel_approval_snapshots' => array(),
			'wp_lel_audit_events'       => array(),
		);

		public function insert( string $table, array $data ): int {
			$this->insert_id = count( $this->rows[ $table ] ?? array() ) + 1;
			$data['id']      = $this->insert_id;
			$this->rows[ $table ][] = $data;
			return 1;
		}

		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
				$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function get_row( string $query, string $output = OBJECT ) {
			preg_match( '/post_id = (\d+)/', $query, $post_match );
			preg_match( "/approval_type = '([^']+)'/", $query, $type_match );
			$rows = array_filter(
				$this->rows['wp_lel_approval_snapshots'],
				static function ( array $row ) use ( $post_match, $type_match ): bool {
					return (int) $row['post_id'] === (int) ( $post_match[1] ?? 0 )
						&& (string) $row['approval_type'] === (string) ( $type_match[1] ?? '')
						&& 'approved' === (string) $row['approval_status']
						&& empty( $row['invalidated_at'] );
				}
			);
			$rows = array_values( $rows );
			$row = $rows ? end( $rows ) : null;
			return $row ? ( ARRAY_A === $output ? $row : (object) $row ) : null;
		}

		public function get_var( string $query ) {
			if ( false !== strpos( $query, 'GET_LOCK' ) || false !== strpos( $query, 'RELEASE_LOCK' ) ) {
				return '1';
			}
			if ( false !== strpos( $query, 'SELECT 1' ) ) {
				return '1';
			}
			if ( preg_match( '/SHOW TABLES LIKE [\'\"]?([^\'\" ]+)/', $query, $match ) ) {
				return isset( $this->rows[ $match[1] ] ) ? $match[1] : null;
			}
			if ( false !== strpos( $query, 'SELECT event_hash' ) ) {
				$rows = $this->rows['wp_lel_audit_events'];
				$row  = $rows ? end( $rows ) : null;
				return $row['event_hash'] ?? '';
			}
			return 0;
		}

		public function query( string $query ): int {
			if ( 0 === stripos( trim( $query ), 'UPDATE wp_lel_approval_snapshots' ) ) {
				preg_match( '/post_id = (\d+)/', $query, $post_match );
				preg_match( "/approval_type = '([^']+)'/", $query, $type_match );
				$count = 0;
				foreach ( $this->rows['wp_lel_approval_snapshots'] as &$row ) {
					if ( (int) $row['post_id'] === (int) ( $post_match[1] ?? 0 ) && (string) $row['approval_type'] === (string) ( $type_match[1] ?? '' ) && empty( $row['invalidated_at'] ) ) {
						$row['invalidated_at'] = '2026-07-22 00:00:00';
						++$count;
					}
				}
				unset( $row );
				return $count;
			}
			return 0;
		}
	};
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post ): int {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return (int) ( $GLOBALS['lel_test_thumbnails'][ $post_id ] ?? 0 );
	}
}

require_once LONGEVITY_CORE_PATH . 'class-gate-result.php';
require_once LONGEVITY_CORE_PATH . 'class-date-validator.php';
require_once LONGEVITY_CORE_PATH . 'class-runtime-config.php';
require_once LONGEVITY_CORE_PATH . 'class-routes.php';
require_once LONGEVITY_CORE_PATH . 'class-seo.php';
require_once LONGEVITY_CORE_PATH . 'class-review-methodology.php';
require_once LONGEVITY_CORE_PATH . 'class-meta-registry.php';
require_once LONGEVITY_CORE_PATH . 'class-meta-authorization.php';
require_once LONGEVITY_CORE_PATH . 'class-reviewer-credentials.php';
require_once LONGEVITY_CORE_PATH . 'class-approval-fingerprint.php';
require_once LONGEVITY_CORE_PATH . 'class-approval-repository.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-lock.php';
require_once LONGEVITY_CORE_PATH . 'class-audit-log.php';
require_once LONGEVITY_CORE_PATH . 'class-approval-service.php';
require_once LONGEVITY_CORE_PATH . 'class-claims.php';
require_once LONGEVITY_CORE_PATH . 'class-affiliate-registry.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-gates.php';
require_once LONGEVITY_CORE_PATH . 'class-rankings.php';
require_once LONGEVITY_CORE_PATH . 'class-public-contact.php';
require_once LONGEVITY_CORE_PATH . 'class-public-content.php';
require_once LONGEVITY_CORE_PATH . 'class-public-nav.php';
require_once LONGEVITY_CORE_PATH . 'class-public-trust.php';
require_once LONGEVITY_CORE_PATH . 'class-public-rankings.php';
require_once LONGEVITY_CORE_PATH . 'class-admin-ui.php';
require_once LONGEVITY_CORE_PATH . 'class-content-discovery.php';
require_once LONGEVITY_CORE_PATH . 'class-rest-api.php';
require_once LONGEVITY_CORE_PATH . 'class-corrections.php';
require_once LONGEVITY_CORE_PATH . 'class-logger.php';
require_once LONGEVITY_CORE_PATH . 'class-migrations.php';
require_once LONGEVITY_CORE_PATH . 'class-freshness-repository.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-lock.php';
require_once LONGEVITY_CORE_PATH . 'class-dependency-index.php';
require_once LONGEVITY_CORE_PATH . 'class-invalidation-queue.php';
require_once LONGEVITY_CORE_PATH . 'class-system-readiness.php';
require_once LONGEVITY_CORE_PATH . 'class-metrics.php';

// Enable Audit_Log test mode: record() returns positive ID without DB writes.
Longevity\Core\Audit_Log::set_test_mode( true );


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


	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $option, $value, bool $autoload = false ): bool {
			$GLOBALS['lel_test_options'][ $option ] = $value;
			return true;
		}
	}
	if ( ! function_exists( 'delete_option' ) ) {
		function delete_option( string $option ): bool {
			unset( $GLOBALS['lel_test_options'][ $option ] );
			return true;
		}
	}
	if ( ! function_exists( 'add_option' ) ) {
		function add_option( string $option, $value = '', string $autoload = 'yes' ): bool {
			$GLOBALS['lel_test_options'][ $option ] = $value;
			return true;
		}
	}
	if ( ! function_exists( 'wp_installing' ) ) {
		function wp_installing(): bool { return false; }
	}

	if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ): string { return (string) json_encode( $value, $flags, $depth ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int { return (int) ( $GLOBALS['lel_test_current_user_id'] ?? 0 ); }
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $user_id, string $capability, ...$args ): bool {
		unset( $args );
		return in_array( $capability, $GLOBALS['lel_test_user_caps'][ $user_id ] ?? array(), true );
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key, bool $single = false ) { unset( $single ); return $GLOBALS['lel_test_user_meta'][ $user_id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $key, $value ): bool { $GLOBALS['lel_test_user_meta'][ $user_id ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( int $user_id, string $key ): bool { unset( $GLOBALS['lel_test_user_meta'][ $user_id ][ $key ] ); return true; }
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) { return $GLOBALS['lel_test_users'][ $user_id ] ?? (object) array( 'ID' => $user_id, 'display_name' => 'User ' . $user_id ); }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post ): string { $obj = get_post( $post ); return $obj ? (string) $obj->post_type : (string) ( $GLOBALS['lel_test_post_types'][ (int) $post ] ?? '' ); }
}
if ( ! function_exists( 'register_rest_field' ) ) {
	function register_rest_field( string $post_type, string $field, array $args ): void { $GLOBALS['lel_test_rest_fields'][] = compact( 'post_type', 'field', 'args' ); }
}
if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( string $post_type, string $meta_key, array $args = array() ): void {
		$GLOBALS['lel_test_registered_meta'][ $post_type ][ $meta_key ] = $args;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool { unset( $GLOBALS['lel_test_meta'][ $post_id ][ $key ] ); return true; }
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string { return 'test-salt-' . $scheme; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000001'; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( ?bool $upload_bases = null, ?bool $create_dir = null, ?bool $refresh_cache = null ): array {
		return array( 'basedir' => '/tmp/wp-content/uploads', 'baseurl' => 'http://example.com/wp-content/uploads', 'path' => '/tmp/wp-content/uploads', 'url' => 'http://example.com/wp-content/uploads', 'error' => false );
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
		$GLOBALS['lel_test_last_get_posts_args'] = $args;
		return $GLOBALS['lel_test_get_posts_result'] ?? array();
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $query_vars = array();
		public int $found_posts = 0;
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
		public string $post_title = '';
		public string $post_excerpt = '';
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

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors = array();
		private string $code = '';
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code = $code;
			if ( '' !== $message ) {
				$this->errors[ $code ][] = $message;
			}
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message( string $code = '' ): string {
			if ( '' === $code ) { $code = $this->code; }
			return $this->errors[ $code ][0] ?? '';
		}
	}
}
