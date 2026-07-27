<?php

namespace Longevity\Core;

use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class SEO {
	private const PRIORITY = 4;

	private static ?bool $provider_active = null;

	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'output_canonical' ), self::PRIORITY );
		add_action( 'wp_head', array( self::class, 'output_meta_description' ), self::PRIORITY );
		add_filter( 'wp_robots', array( self::class, 'filter_robots' ) );
		add_action( 'wp_head', array( self::class, 'output_social_meta' ), self::PRIORITY );
		add_action( 'init', array( self::class, 'remove_core_canonical' ), 10 );
	}

	public static function remove_core_canonical(): void {
		remove_action( 'wp_head', 'rel_canonical', 10 );
	}

	private static function provider_active(): bool {
		if ( null === self::$provider_active ) {
			self::$provider_active = defined( 'WPSEO_VERSION' )
				|| class_exists( 'RankMath' )
				|| class_exists( 'The_SEO_Framework\Load' );
		}
		return self::$provider_active;
	}

	public static function resolve_description( int $post_id = 0 ): string {
		if ( 0 === $post_id ) {
			return (string) get_bloginfo( 'description' );
		}
		$desc = (string) get_post_meta( $post_id, 'seo_description', true );
		if ( '' !== $desc ) {
			return $desc;
		}
		$desc = trim( (string) get_the_excerpt( $post_id ) );
		if ( '' !== $desc ) {
			return $desc;
		}
		$desc = trim( (string) get_post_meta( $post_id, 'content_summary', true ) );
		if ( '' !== $desc ) {
			return $desc;
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/', ' ', $content );
		if ( mb_strlen( $content ) > 160 ) {
			$content = mb_substr( $content, 0, 157 ) . '...';
		}
		if ( '' !== $content ) {
			return $content;
		}
		return (string) get_bloginfo( 'description' );
	}

	public static function output_meta_description(): void {
		if ( self::provider_active() ) {
			return;
		}
		$desc = '';
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$desc    = self::resolve_description( $post_id );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$desc = term_description( $term );
				if ( $desc ) {
					$desc = wp_strip_all_tags( $desc );
				}
			}
		} elseif ( is_search() ) {
			$desc = sprintf(
				'Search results for "%s" on %s.',
				get_search_query(),
				get_bloginfo( 'name' )
			);
		}
		if ( '' === $desc ) {
			$desc = (string) get_bloginfo( 'description' );
		}
		$desc = wp_strip_all_tags( $desc );
		$desc = preg_replace( '/\s+/', ' ', $desc );
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
	}

	/** Resolve one canonical URL for the current request; shared by canonical and social output. */
	public static function canonical_url(): string {
		$url = '';
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$url     = (string) get_permalink( $post_id );
		} elseif ( is_category() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$key = Routes::category_key( $term->term_id );
				if ( $key ) {
					$url = (string) Routes::category_url( $key );
				}
				if ( '' === $url ) {
					$link = get_term_link( $term );
					$url  = is_wp_error( $link ) ? '' : (string) $link;
				}
			}
		} elseif ( is_home() || is_front_page() ) {
			$url = home_url( '/' );
		} elseif ( is_search() ) {
			$url = home_url( '/?s=' . rawurlencode( get_search_query() ) );
		} elseif ( is_post_type_archive() ) {
			$url = (string) get_post_type_archive_link( get_query_var( 'post_type' ) ?: 'review' );
		}
		return $url;
	}

	public static function output_canonical(): void {
		if ( self::provider_active() ) {
			return;
		}
		$url = self::canonical_url();
		if ( '' !== $url ) {
			echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
		}
	}

	public static function filter_robots( array $robots ): array {
		if ( self::provider_active() ) {
			return $robots;
		}
		if ( is_search() ) {
			$robots['noindex'] = true;
		} elseif ( is_404() ) {
			$robots['noindex'] = true;
		} elseif ( is_post_type_archive( 'review' ) ) {
			$robots['noindex'] = true;
		} elseif ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( $post_id && '1' === get_post_meta( $post_id, '_longevity_noindex', true ) ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
			}
		}
		return $robots;
	}

	public static function output_social_meta(): void {
		if ( self::provider_active() ) {
			return;
		}
		$post_id     = is_singular() ? get_queried_object_id() : 0;
		$title       = $post_id ? get_the_title( $post_id ) : wp_get_document_title();
		$url         = self::canonical_url();
		if ( '' === $url ) {
			$url = $post_id ? (string) get_permalink( $post_id ) : home_url( '/' );
		}
		$description = $post_id ? self::resolve_description( $post_id ) : (string) get_bloginfo( 'description' );
		$type        = is_singular( array( 'post', 'review' ) ) ? 'article' : 'website';
		$image       = $post_id ? wp_get_attachment_image_url( get_post_thumbnail_id( $post_id ), 'full' ) : '';

		$tags = array(
			array(
				'property' => 'og:site_name',
				'content'  => get_bloginfo( 'name' ),
			),
			array(
				'property' => 'og:title',
				'content'  => $title,
			),
			array(
				'property' => 'og:description',
				'content'  => $description,
			),
			array(
				'property' => 'og:url',
				'content'  => $url,
			),
			array(
				'property' => 'og:type',
				'content'  => $type,
			),
		);
		if ( $post_id ) {
			$tags[] = array(
				'property' => 'article:author',
				'content'  => (string) get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) ),
			);
		}
		$tags[] = array(
			'name'    => 'twitter:card',
			'content' => $image ? 'summary_large_image' : 'summary',
		);
		$tags[] = array(
			'name'    => 'twitter:title',
			'content' => $title,
		);
		$tags[] = array(
			'name'    => 'twitter:description',
			'content' => $description,
		);
		if ( $image ) {
			$tags[] = array(
				'property' => 'og:image',
				'content'  => $image,
			);
			$tags[] = array(
				'name'    => 'twitter:image',
				'content' => $image,
			);
		}
		foreach ( $tags as $tag ) {
			$attr = isset( $tag['property'] ) ? 'property="' . esc_attr( $tag['property'] ) . '"' : 'name="' . esc_attr( $tag['name'] ) . '"';
			echo '<meta ' . $attr . ' content="' . esc_attr( wp_strip_all_tags( (string) $tag['content'] ) ) . '">' . "\n";
		}
	}
}
