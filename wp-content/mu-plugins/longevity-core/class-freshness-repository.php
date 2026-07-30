<?php
/**
 * Fair content freshness selection.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Selects never-scanned and least-recently-scanned records without starvation. */
final class Freshness_Repository {
	/** Select a bounded fair batch from WordPress. */
	public static function next_batch( int $batch_size ): array {
		$batch_size = min( 250, max( 1, $batch_size ) );
		$never      = get_posts(
			array(
				'post_type'              => array( 'post', 'review' ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => $batch_size,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_lel_freshness_last_scanned_at',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		if ( count( $never ) >= $batch_size ) {
			return array_map( 'intval', $never );
		}
		$remaining = $batch_size - count( $never );
		$scanned   = get_posts(
			array(
				'post_type'              => array( 'post', 'review' ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => $remaining,
				'fields'                 => 'ids',
				'meta_key'               => '_lel_freshness_last_scanned_at',
				'orderby'                => array(
					'meta_value' => 'ASC',
					'ID'         => 'ASC',
				),
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return array_values( array_unique( array_map( 'intval', array_merge( $never, $scanned ) ) ) );
	}

	/** Pure fairness helper used by unit tests. */
	public static function select_fair_batch( array $records, int $batch_size ): array {
		usort(
			$records,
			static function ( array $left, array $right ): int {
				$left_scan  = (string) ( $left['last_scanned_at'] ?? '' );
				$right_scan = (string) ( $right['last_scanned_at'] ?? '' );
				if ( $left_scan === $right_scan ) {
					return (int) $left['id'] <=> (int) $right['id'];
				}
				if ( '' === $left_scan ) {
					return -1;
				}
				if ( '' === $right_scan ) {
					return 1;
				}
				return strcmp( $left_scan, $right_scan );
			}
		);
		return array_slice( $records, 0, max( 0, $batch_size ) );
	}

	/** Count all eligible records. */
	public static function eligible_total(): int {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'review' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			)
		);
		return (int) $query->found_posts;
	}
}
