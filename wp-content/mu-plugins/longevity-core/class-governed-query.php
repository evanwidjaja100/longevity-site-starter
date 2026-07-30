<?php
/**
 * Complete, batched retrieval of governed records.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Keyset-paginated post lookups that never silently truncate.
 *
 * Governance payloads (approval fingerprints, dependency cascades, gate
 * checks) must cover EVERY matching record; a capped page would let record
 * N+1 escape approval coverage. Batches of 200 are fetched by ascending ID
 * until exhaustion, and exceeding the hard safety cap throws instead of
 * truncating.
 */
final class Governed_Query {
	public const BATCH_SIZE = 200;

	/** Absolute safety bound; exceeding it is an integrity error, never a truncation. */
	public const HARD_CAP = 20000;

	/**
	 * Fetch ALL post IDs of the given types carrying an exact meta key/value pair.
	 *
	 * @param list<string>      $post_types    Post types to match.
	 * @param string            $meta_key      Meta key to match.
	 * @param string            $meta_value    Exact meta value to match.
	 * @param list<string>|null $post_statuses Statuses to match, or null for all.
	 * @param int               $hard_cap      Safety bound override (tests only).
	 * @return list<int> Matching IDs in ascending order.
	 * @throws \RuntimeException When more than $hard_cap records match.
	 */
	public static function ids_by_meta( array $post_types, string $meta_key, string $meta_value, ?array $post_statuses = null, int $hard_cap = self::HARD_CAP ): array {
		global $wpdb;
		$ids     = array();
		$last_id = 0;
		while ( true ) {
			$sql    = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = %s AND pm.meta_value = %s";
			$params = array( $meta_key, $meta_value );
			$sql   .= ' AND p.post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
			$params = array_merge( $params, array_values( $post_types ) );
			if ( null !== $post_statuses && array() !== $post_statuses ) {
				$sql   .= ' AND p.post_status IN (' . implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) ) . ')';
				$params = array_merge( $params, array_values( $post_statuses ) );
			}
			$sql     .= ' AND p.ID > %d ORDER BY p.ID ASC LIMIT ' . self::BATCH_SIZE;
			$params[] = $last_id;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders assembled above, values bound via prepare.
			$batch = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );
			if ( ! is_array( $batch ) || array() === $batch ) {
				break;
			}
			foreach ( $batch as $id ) {
				$ids[] = (int) $id;
			}
			if ( count( $ids ) > $hard_cap ) {
				throw new \RuntimeException(
					esc_html( sprintf( 'Governed query for meta %s exceeded the safety cap of %d records; refusing to truncate.', $meta_key, $hard_cap ) )
				);
			}
			$last_id = (int) end( $batch );
			if ( count( $batch ) < self::BATCH_SIZE ) {
				break;
			}
		}
		return $ids;
	}
}
