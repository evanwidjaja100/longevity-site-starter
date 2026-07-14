<?php
/**
 * Affiliate registry and disclosed link rendering.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Controls affiliate destinations and disclosure metadata. */
final class Affiliate_Registry {
	/** Register hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 12 );
	}

	/** Register registry metadata. */
	public static function register_meta(): void {
		$fields = array(
			'merchant_id'              => 'text',
			'merchant_name'            => 'text',
			'merchant_domain'          => 'text',
			'program_name'             => 'text',
			'relationship_status'      => 'status',
			'effective_date'           => 'date',
			'expiration_date'          => 'date',
			'disclosure_language'      => 'textarea',
			'editorial_independence_note' => 'textarea',
			'owner_user_id'            => 'absint',
			'last_verified_date'       => 'date',
		);
		foreach ( $fields as $key => $rule ) {
			register_post_meta(
				'lel_affiliate',
				$key,
				array(
					'type'              => 'absint' === $rule ? 'integer' : 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static function ( $value ) use ( $rule ) {
						if ( 'status' === $rule ) {
							$value = sanitize_key( (string) $value );
							return in_array( $value, array( 'active', 'paused', 'expired', 'terminated' ), true ) ? $value : 'paused';
						}
						return Meta_Registry::sanitize_value( $rule, $value );
					},
					'auth_callback'     => static fn() => current_user_can( 'manage_affiliate_registry' ),
				)
			);
		}
	}

	/** Whether content contains an affiliate shortcode or sponsored link marker. */
	public static function content_has_affiliate_link( string $content ): bool {
		return has_shortcode( $content, 'affiliate_link' ) || false !== stripos( $content, 'rel="sponsored' ) || false !== stripos( $content, "rel='sponsored" );
	}

	/** Verify every affiliate destination present in content against an active registry record. */
	public static function all_destinations_registered( string $content ): bool {
		$urls = array();
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'affiliate_link' ) ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attributes = shortcode_parse_atts( $match[3] );
				if ( is_array( $attributes ) && ! empty( $attributes['url'] ) ) {
					$urls[] = (string) $attributes['url'];
				}
			}
		}
		if ( preg_match_all( '/<a\b(?=[^>]*\brel=["\'][^"\']*sponsored[^"\']*["\'])(?=[^>]*\bhref=["\']([^"\']+)["\'])[^>]*>/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		if ( empty( $urls ) ) {
			return true;
		}
		foreach ( $urls as $url ) {
			if ( ! self::find_by_url( $url ) ) {
				return false;
			}
		}
		return true;
	}

	/** Find an active merchant registry record by URL host. */
	public static function find_by_url( string $url ): ?\WP_Post {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		if ( '' === $host ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lel_affiliate',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array( 'key' => 'merchant_domain', 'value' => $host ),
					array( 'key' => 'relationship_status', 'value' => 'active' ),
				),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/** Render a safe, instrumented affiliate link. */
	public static function render_link( string $url, string $label, string $placement = 'article' ): string {
		$url = esc_url( $url );
		if ( '' === $url ) {
			return '';
		}
		$merchant = self::find_by_url( $url );
		if ( ! $merchant ) {
			return current_user_can( 'manage_affiliate_registry' )
				? '<p class="longevity-affiliate-warning" role="note">' . esc_html__( 'Affiliate link withheld: this destination is not active in the affiliate registry.', 'longevity-core' ) . '</p>'
				: '';
		}
		$attributes = array(
			'class'          => 'wp-element-button longevity-affiliate-link',
			'href'           => $url,
			'rel'            => 'sponsored nofollow noopener',
			'target'         => '_blank',
			'data-lel-event' => 'affiliate_click',
			'data-placement' => sanitize_key( $placement ),
			'data-merchant'  => sanitize_text_field( (string) get_post_meta( $merchant->ID, 'merchant_id', true ) ),
		);
		$html = '';
		foreach ( $attributes as $name => $value ) {
			$html .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		return sprintf( '<p class="longevity-affiliate-cta"><a%s>%s</a></p>', $html, esc_html( $label ) );
	}
}
