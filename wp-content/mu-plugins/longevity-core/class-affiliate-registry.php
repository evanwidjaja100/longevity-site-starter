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
			'allow_subdomains'         => 'boolean',
		);
		foreach ( $fields as $key => $rule ) {
			register_post_meta(
				'lel_affiliate',
				$key,
				array(
					'type'              => 'boolean' === $rule ? 'boolean' : ( 'absint' === $rule ? 'integer' : 'string' ),
					'single'            => true,
					'show_in_rest'      => false,
				'sanitize_callback' => static fn( $value ) => Meta_Registry::sanitize_value( $rule, $value ),
					'auth_callback'     => static fn() => current_user_can( 'manage_affiliate_relationships' ),
				)
			);
		}
	}

	/** Whether content contains an affiliate shortcode or sponsored link marker. */
	public static function content_has_affiliate_link( string $content ): bool {
		if ( has_shortcode( $content, 'affiliate_link' ) ) {
			return true;
		}
		if ( preg_match_all( '/<a\b[^>]*>/i', $content, $tag_matches ) ) {
			foreach ( $tag_matches[0] as $tag ) {
				if ( self::tag_has_sponsored_rel( $tag ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Verify every affiliate destination present in content against an active registry record. */
	public static function all_destinations_registered( string $content ): bool {
		foreach ( self::destinations_in_content( $content ) as $url ) {
			if ( ! self::find_by_url( $url ) ) {
				return false;
			}
		}
		return true;
	}

	/** Return relationship owners associated with active destinations in content. */
	public static function relationship_owners_for_content( string $content ): array {
		$owners = array();
		foreach ( self::destinations_in_content( $content ) as $url ) {
			$merchant = self::find_by_url( $url );
			if ( $merchant ) {
				$owner = (int) get_post_meta( $merchant->ID, 'owner_user_id', true );
				if ( $owner > 0 ) {
					$owners[] = $owner;
				}
			}
		}
		return array_values( array_unique( $owners ) );
	}

	/** Extract normalized, bounded affiliate destinations without exposing other links. */
	private static function destinations_in_content( string $content ): array {
		$urls = array();
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'affiliate_link' ) ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attributes = shortcode_parse_atts( $match[3] );
				if ( is_array( $attributes ) && ! empty( $attributes['url'] ) ) {
					$urls[] = (string) $attributes['url'];
				}
			}
		}
		// Match anchors with rel containing 'sponsored' in any token order, case-insensitive.
		if ( preg_match_all( '/<a\b[^>]*>/i', $content, $tag_matches ) ) {
			foreach ( $tag_matches[0] as $tag ) {
				if ( ! self::tag_has_sponsored_rel( $tag ) ) {
					continue;
				}
				if ( preg_match( '/\bhref=["\']([^"\']+)["\']/i', $tag, $href_match ) ) {
					$urls[] = $href_match[1];
				}
			}
		}
		return array_slice( array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) ), 0, 100 );
	}

	/** Whether an anchor tag's rel attribute contains 'sponsored' as a token (case-insensitive, any order). */
	private static function tag_has_sponsored_rel( string $tag ): bool {
		if ( ! preg_match( '/\brel=["\']([^"\']*)["\']|\brel=([^\s>]+)/i', $tag, $rel_match ) ) {
			return false;
		}
		$rel_value = '' !== $rel_match[1] ? $rel_match[1] : ( $rel_match[2] ?? '' );
		$tokens    = preg_split( '/[\s]+/', strtolower( trim( $rel_value ) ) ) ?: array();
		return in_array( 'sponsored', $tokens, true );
	}

	/** Find an eligible merchant registry record by normalized destination. */
	public static function find_by_url( string $url ): ?\WP_Post {
		$destination = self::normalize_destination( $url );
		if ( null === $destination ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lel_affiliate',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => 'relationship_status', 'value' => 'active' ) ),
			)
		);
		foreach ( $posts as $post ) {
			$record = array(
				'merchant_domain'   => (string) get_post_meta( $post->ID, 'merchant_domain', true ),
				'relationship_status'=> (string) get_post_meta( $post->ID, 'relationship_status', true ),
				'effective_date'     => (string) get_post_meta( $post->ID, 'effective_date', true ),
				'expiration_date'    => (string) get_post_meta( $post->ID, 'expiration_date', true ),
				'last_verified_date' => (string) get_post_meta( $post->ID, 'last_verified_date', true ),
				'allow_subdomains'   => (bool) get_post_meta( $post->ID, 'allow_subdomains', true ),
			);
			if ( self::relationship_is_eligible( $record, $url ) ) {
				return $post;
			}
		}
		return null;
	}

	/** Normalize and reject unsafe affiliate destinations. */
	public static function normalize_destination( string $url ): ?array {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! in_array( $port, array( 80, 443 ), true ) ) {
			return null;
		}
		$host = self::normalize_domain( (string) $parts['host'] );
		return '' === $host ? null : array( 'scheme' => $scheme, 'host' => $host, 'port' => $port );
	}

	/** Evaluate dates, verification recency and exact/subdomain policy. */
	public static function relationship_is_eligible( array $record, string $url, ?string $today = null ): bool {
		$destination = self::normalize_destination( $url );
		$today       = $today ?: Date_Validator::today();
		if ( null === $destination || ! Date_Validator::is_valid( $today ) || 'active' !== ( $record['relationship_status'] ?? '' ) ) {
			return false;
		}
		$effective = (string) ( $record['effective_date'] ?? '' );
		$expires   = (string) ( $record['expiration_date'] ?? '' );
		$verified  = (string) ( $record['last_verified_date'] ?? '' );
		if ( ! Date_Validator::is_valid( $effective ) || Date_Validator::compare( $effective, $today ) > 0 ) {
			return false;
		}
		if ( '' !== $expires && ( ! Date_Validator::is_valid( $expires ) || Date_Validator::compare( $expires, $today ) < 0 ) ) {
			return false;
		}
		$max_age = min( 1095, max( 1, (int) get_option( 'lel_affiliate_verification_max_age_days', 365 ) ) );
		if ( ! Date_Validator::is_valid( $verified ) || strtotime( $verified . ' 00:00:00 UTC' ) < strtotime( $today . ' 00:00:00 UTC' ) - ( $max_age * DAY_IN_SECONDS ) ) {
			return false;
		}
		$registered = self::normalize_domain( (string) ( $record['merchant_domain'] ?? '' ) );
		if ( '' === $registered ) {
			return false;
		}
		if ( $destination['host'] === $registered ) {
			return true;
		}
		return ! empty( $record['allow_subdomains'] ) && str_ends_with( $destination['host'], '.' . $registered );
	}

	/** Canonical ASCII domain with harmless presentation variants removed. */
	public static function normalize_domain( string $domain ): string {
		$domain = strtolower( rtrim( trim( $domain ), '.' ) );
		$domain = preg_replace( '/^www\./', '', $domain );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $domain, IDNA_DEFAULT, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
			if ( false !== $ascii ) {
				$domain = strtolower( $ascii );
			}
		}
		return preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain ) ? $domain : '';
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
