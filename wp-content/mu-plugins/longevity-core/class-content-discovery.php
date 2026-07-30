<?php
/**
 * Reader-facing search and archive query policy.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Applies bounded, progressively enhanced discovery filters. */
final class Content_Discovery {
	/** Register query and indexation filters. */
	public static function init(): void {
		add_action( 'pre_get_posts', array( self::class, 'filter_main_query' ) );
		add_filter( 'wp_robots', array( self::class, 'search_robots' ) );
	}

	/** Restrict public discovery to supported types and allowlisted GET values. */
	public static function filter_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->is_search() ) {
			$content_type = self::requested_content_type();
			$query->set( 'post_type', 'guide' === $content_type ? 'post' : ( 'review' === $content_type ? 'review' : array( 'post', 'review' ) ) );
			$query->set( 'posts_per_page', 10 );
			$category_slug = self::requested_category();
			if ( $category_slug ) {
				$term = get_term_by( 'slug', $category_slug, 'category' );
				if ( $term ) {
					$query->set( 'category__in', array( $term->term_id ) );
				}
			}
			$sort = self::requested_sort();
			if ( 'newest' === $sort ) {
				$query->set(
					'orderby',
					array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					)
				);
			} elseif ( 'updated' === $sort ) {
				$query->set(
					'orderby',
					array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					)
				);
			}
			return;
		}
		if ( $query->is_category() || $query->is_tag() || $query->is_author() || $query->is_home() ) {
			$query->set( 'post_type', array( 'post', 'review' ) );
			$query->set( 'posts_per_page', 10 );
		}
	}

	/** Return an allowlisted content type. */
	public static function requested_content_type(): string {
		$value = isset( $_GET['content_type'] ) ? sanitize_key( wp_unslash( $_GET['content_type'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; value is sanitized and causes no state change.
		return in_array( $value, array( 'all', 'guide', 'review' ), true ) ? $value : 'all';
	}

	/** Return an allowlisted category slug from GET, or empty string. */
	public static function requested_category(): string {
		$value = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; value is sanitized and causes no state change.
		if ( '' === $value ) {
			return '';
		}
		$term = get_term_by( 'slug', $value, 'category' );
		return $term ? $value : '';
	}

	/** Return an allowlisted sort value. */
	public static function requested_sort(): string {
		$value = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'relevance'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; value is sanitized and causes no state change.
		return in_array( $value, array( 'relevance', 'newest', 'updated' ), true ) ? $value : 'relevance';
	}

	/** Search-result pages are useful to readers but should not be indexed. */
	public static function search_robots( array $robots ): array {
		if ( is_search() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
}
