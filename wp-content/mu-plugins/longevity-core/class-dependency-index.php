<?php
/**
 * Dependency index for fast reverse-lookups of content relationships.
 *
 * Replaces unbounded posts_per_page => -1 scans with indexed queries.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Maintains a materialized index of dependency→parent relationships. */
final class Dependency_Index {
	/** Schema version for this table. */
	public const SCHEMA_VERSION = '1.1.0';

	/** Source-to-index algorithm generation; changes force a fresh backfill. */
	public const DATA_GENERATION = '3';

	/**
	 * Affiliate edge-resolution counters for the current backfill run.
	 *
	 * @var array{unresolved: int, ambiguous: int, broad_fallbacks: int}
	 */
	private static array $affiliate_stats = array(
		'unresolved'      => 0,
		'ambiguous'       => 0,
		'broad_fallbacks' => 0,
	);

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_dependencies' : 'wp_lel_dependencies';
	}

	/** Install the dependency index table (additive, idempotent). */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dependency_type varchar(40) NOT NULL,
			dependency_id bigint(20) unsigned NOT NULL,
			parent_post_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY dep_parent (dependency_type,dependency_id,parent_post_id),
			KEY parent_lookup (parent_post_id,dependency_type),
			KEY dep_lookup (dependency_type,dependency_id)
		) {$charset};";
		dbDelta( $sql );
		if ( ! self::exists() ) {
			throw new \RuntimeException( 'Dependency index table was not created.' );
		}
	}

	/** Whether the table exists. */
	public static function exists(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Register a dependency relationship (idempotent upsert).
	 *
	 * @param string $type    Dependency type (e.g. 'lel_claim', 'lel_source', 'lel_test_record', 'lel_protocol', 'lel_affiliate', 'credential').
	 * @param int    $dep_id  The dependency post/user ID.
	 * @param int    $parent  The parent post ID that depends on this entity.
	 */
	public static function register( string $type, int $dep_id, int $parent ): void {
		global $wpdb;
		if ( ! self::exists() || $dep_id <= 0 || $parent <= 0 ) {
			return;
		}
		$table = self::table_name();
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (dependency_type, dependency_id, parent_post_id, created_at) VALUES (%s, %d, %d, NOW())",
				$type,
				$dep_id,
				$parent
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Dependency registration failed: ' . self::database_error( $wpdb ) );
		}
	}

	/**
	 * Remove a specific dependency relationship.
	 *
	 * @param string $type   Dependency type.
	 * @param int    $dep_id The dependency post/user ID.
	 * @param int    $parent The parent post ID.
	 */
	public static function remove( string $type, int $dep_id, int $parent ): void {
		global $wpdb;
		if ( ! self::exists() ) {
			return;
		}
		$table = self::table_name();
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE dependency_type = %s AND dependency_id = %d AND parent_post_id = %d",
				$type,
				$dep_id,
				$parent
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Dependency removal failed: ' . self::database_error( $wpdb ) );
		}
	}

	/**
	 * Remove all relationships for a dependency (used when dependency is deleted).
	 *
	 * @param string $type   Dependency type.
	 * @param int    $dep_id The dependency post/user ID.
	 */
	public static function remove_all_for_dependency( string $type, int $dep_id ): void {
		global $wpdb;
		if ( ! self::exists() ) {
			return;
		}
		$table = self::table_name();
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE dependency_type = %s AND dependency_id = %d",
				$type,
				$dep_id
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Dependency cleanup failed: ' . self::database_error( $wpdb ) );
		}
	}

	/**
	 * Atomically replace all dependencies of one type for a parent.
	 *
	 * Delete + insert run in one transaction so concurrent readers never
	 * observe a half-written dependency set.
	 *
	 * @param string    $type    Dependency type.
	 * @param int       $parent  The parent post ID.
	 * @param list<int> $dep_ids The full new set of dependency IDs.
	 */
	public static function replace_dependencies( string $type, int $parent, array $dep_ids ): void {
		global $wpdb;
		if ( ! self::exists() || $parent <= 0 ) {
			return;
		}
		$table = self::table_name();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Could not start dependency-index replacement transaction: ' . self::database_error( $wpdb ) );
		}
		$committed = false;
		try {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE dependency_type = %s AND parent_post_id = %d",
					$type,
					$parent
				)
			);
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Could not remove stale dependency-index rows: ' . self::database_error( $wpdb ) );
			}
			foreach ( array_unique( array_map( 'intval', $dep_ids ) ) as $dep_id ) {
				if ( $dep_id <= 0 ) {
					continue;
				}
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$table} (dependency_type, dependency_id, parent_post_id, created_at) VALUES (%s, %d, %d, NOW())",
						$type,
						$dep_id,
						$parent
					)
				);
				if ( false === $inserted ) {
					throw new \RuntimeException( sprintf( 'Could not index dependency %d: %s', $dep_id, self::database_error( $wpdb ) ) );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Dependency-index replacement COMMIT outcome is unknown: ' . self::database_error( $wpdb ) );
			}
			$committed = true;
		} finally {
			if ( ! $committed && false === $wpdb->query( 'ROLLBACK' ) ) {
				Logger::error( 'dependency_transaction_rollback_failed', array( 'parent_post_id' => $parent, 'dependency_type' => $type, 'error' => self::database_error( $wpdb ) ) );
			}
		}
	}

	/**
	 * Backfill the index from existing governed content.
	 *
	 * Resumable via a keyset cursor option; records a completion marker
	 * consumed by readiness. Completion is generation-bound and recorded only
	 * after a source-of-truth drift check passes.
	 *
	 * @param bool $dry_run When true, nothing is written.
	 * @param int  $batch   Parents per page (bounded 10–500).
	 * @return array{parents_scanned: int, dependencies_indexed: int, complete: bool, dry_run: bool, generation: string, drift: array{valid: bool, parents_checked: int, missing: int, stale: int, orphans: int}, affiliate: array{unresolved: int, ambiguous: int, broad_fallbacks: int}}
	 */
	public static function run_backfill( bool $dry_run = false, int $batch = 200 ): array {
		global $wpdb;
		$batch                 = min( 500, max( 10, $batch ) );
		self::$affiliate_stats = array(
			'unresolved'      => 0,
			'ambiguous'       => 0,
			'broad_fallbacks' => 0,
		);
		$result = array(
			'parents_scanned'      => 0,
			'dependencies_indexed' => 0,
			'complete'             => false,
			'dry_run'              => $dry_run,
			'generation'           => self::DATA_GENERATION,
			'drift'                => array( 'valid' => false, 'parents_checked' => 0, 'missing' => 0, 'stale' => 0, 'orphans' => 0 ),
			'affiliate'            => self::$affiliate_stats,
		);
		if ( ! self::exists() && ! $dry_run ) {
			return $result;
		}
		if ( ! $dry_run && self::DATA_GENERATION !== (string) get_option( 'lel_dependency_backfill_generation', '' ) ) {
			self::write_option( 'lel_dependency_backfill_cursor', 0 );
			self::write_option( 'lel_dependency_index_backfilled_at', '' );
			self::write_option( 'lel_dependency_backfill_generation', self::DATA_GENERATION );
		}
		$cursor = $dry_run ? 0 : (int) get_option( 'lel_dependency_backfill_cursor', 0 );
		while ( true ) {
			self::clear_database_error( $wpdb );
			$page             = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','review') AND post_status NOT IN ('trash','auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d",
					$cursor,
					$batch
				)
			);
			if ( self::database_failed( $wpdb ) ) {
				throw new \RuntimeException( 'Dependency backfill query failed: ' . (string) $wpdb->last_error );
			}
			if ( ! is_array( $page ) || array() === $page ) {
				break;
			}
			foreach ( $page as $parent ) {
				$parent = (int) $parent;
				++$result['parents_scanned'];
				$result['dependencies_indexed'] += self::reindex_parent( $parent, $dry_run );
			}
			$cursor = (int) end( $page );
			if ( ! $dry_run ) {
				self::write_option( 'lel_dependency_backfill_cursor', $cursor );
			}
			if ( count( $page ) < $batch ) {
				break;
			}
		}
		$result['affiliate'] = self::$affiliate_stats;
		$result['drift']     = $dry_run ? $result['drift'] : self::verify_drift( $batch );
		$result['complete']  = $dry_run || true === $result['drift']['valid'];
		if ( ! $dry_run && $result['complete'] ) {
			self::write_option( 'lel_dependency_index_generation', self::DATA_GENERATION );
			self::write_option( 'lel_dependency_index_schema_version', self::SCHEMA_VERSION );
			self::write_option( 'lel_dependency_index_backfilled_at', gmdate( DATE_W3C ) );
			self::write_option( 'lel_affiliate_edge_report', $result['affiliate'] );
			if ( ! delete_option( 'lel_dependency_backfill_cursor' ) && false !== get_option( 'lel_dependency_backfill_cursor', false ) ) {
				throw new \RuntimeException( 'Could not clear dependency backfill cursor.' );
			}
			if ( ! delete_option( 'lel_dependency_backfill_generation' ) && false !== get_option( 'lel_dependency_backfill_generation', false ) ) {
				throw new \RuntimeException( 'Could not clear dependency backfill generation.' );
			}
		} elseif ( ! $dry_run ) {
			self::write_option( 'lel_dependency_index_backfilled_at', '' );
		}
		return $result;
	}

	/**
	 * Index every dependency type for one parent post.
	 *
	 * @param int  $parent  Parent post ID.
	 * @param bool $dry_run When true, only counts.
	 * @return int Number of dependency relationships found.
	 */
	public static function reindex_parent( int $parent, bool $dry_run = false ): int {
		$sets  = self::source_sets( $parent );
		$found = 0;
		foreach ( $sets as $type => $ids ) {
			$found += count( $ids );
			if ( ! $dry_run ) {
				self::replace_dependencies( $type, $parent, $ids );
			}
		}
		return $found;
	}

	/** Verify source-of-truth edges against the complete materialized index. */
	public static function verify_drift( int $batch = 200 ): array {
		global $wpdb;
		$batch  = min( 500, max( 10, $batch ) );
		$result = array( 'valid' => false, 'parents_checked' => 0, 'missing' => 0, 'stale' => 0, 'orphans' => 0 );
		if ( ! self::exists() ) {
			return $result;
		}
		$cursor = 0;
		$seen   = array();
		while ( true ) {
			self::clear_database_error( $wpdb );
			$page             = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','review') AND post_status NOT IN ('trash','auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d", $cursor, $batch ) );
			if ( self::database_failed( $wpdb ) ) {
				throw new \RuntimeException( 'Dependency drift parent query failed: ' . self::database_error( $wpdb ) );
			}
			if ( ! is_array( $page ) || array() === $page ) {
				break;
			}
			foreach ( $page as $parent_id ) {
				$parent_id          = (int) $parent_id;
				$seen[ $parent_id ] = true;
				++$result['parents_checked'];
				$expected = self::source_sets( $parent_id );
				$actual   = self::indexed_sets( $parent_id );
				foreach ( array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) ) as $type ) {
					$result['missing'] += count( array_diff( $expected[ $type ] ?? array(), $actual[ $type ] ?? array() ) );
					$result['stale']   += count( array_diff( $actual[ $type ] ?? array(), $expected[ $type ] ?? array() ) );
				}
			}
			$cursor = (int) end( $page );
			if ( count( $page ) < $batch ) {
				break;
			}
		}
		self::clear_database_error( $wpdb );
		$parents          = $wpdb->get_col( 'SELECT DISTINCT parent_post_id FROM ' . self::table_name() );
		if ( self::database_failed( $wpdb ) ) {
			throw new \RuntimeException( 'Dependency orphan query failed: ' . self::database_error( $wpdb ) );
		}
		foreach ( array_unique( array_map( 'intval', is_array( $parents ) ? $parents : array() ) ) as $parent_id ) {
			if ( ! isset( $seen[ $parent_id ] ) ) {
				++$result['orphans'];
			}
		}
		$result['valid'] = 0 === $result['missing'] && 0 === $result['stale'] && 0 === $result['orphans'];
		return $result;
	}

	/** Whether the completion marker is current and still drift-free. */
	public static function backfill_is_current(): bool {
		return self::backfill_marker_current() && true === self::verify_drift()['valid'];
	}

	/** Cheap generation/schema/completion marker check without a drift scan. */
	public static function backfill_marker_current(): bool {
		return '' !== (string) get_option( 'lel_dependency_index_backfilled_at', '' )
			&& self::DATA_GENERATION === (string) get_option( 'lel_dependency_index_generation', '' )
			&& self::SCHEMA_VERSION === (string) get_option( 'lel_dependency_index_schema_version', '' );
	}

	/** Count materialized affiliate edges; null when the count is unavailable. */
	public static function affiliate_edge_count(): ?int {
		global $wpdb;
		if ( ! self::exists() ) {
			return null;
		}
		self::clear_database_error( $wpdb );
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . " WHERE dependency_type = 'lel_affiliate'" );
		if ( self::database_failed( $wpdb ) || null === $count ) {
			return null;
		}
		return (int) $count;
	}

	/** Build the complete source-of-truth dependency sets for one parent. */
	private static function source_sets( int $parent ): array {
		global $wpdb;
		self::clear_database_error( $wpdb );
		$claim_ids = Governed_Query::ids_by_meta( array( 'lel_claim' ), 'post_id', (string) $parent );
		if ( self::database_failed( $wpdb ) ) {
			throw new \RuntimeException( 'Claim dependency query failed: ' . self::database_error( $wpdb ) );
		}
		$source_ids = array();
		foreach ( $claim_ids as $claim_id ) {
			$source_id = (int) get_post_meta( $claim_id, 'source_id', true );
			if ( $source_id > 0 ) {
				$source_ids[] = $source_id;
			}
		}
		$record_id   = (int) get_post_meta( $parent, 'test_record_id', true );
		$protocol_id = $record_id > 0 ? (int) get_post_meta( $record_id, 'protocol_id', true ) : 0;
		$reviewer_id = (int) get_post_meta( $parent, 'medical_reviewer_user_id', true );
		return array(
			'lel_claim'       => array_values( array_unique( array_map( 'intval', $claim_ids ) ) ),
			'lel_source'      => array_values( array_unique( $source_ids ) ),
			'lel_test_record' => $record_id > 0 ? array( $record_id ) : array(),
			'lel_protocol'    => $protocol_id > 0 ? array( $protocol_id ) : array(),
			'credential'      => $reviewer_id > 0 ? array( $reviewer_id ) : array(),
			'lel_affiliate'   => self::affiliate_ids_for_parent( $parent ),
		);
	}

	/** Read all indexed edges for one parent. */
	private static function indexed_sets( int $parent ): array {
		global $wpdb;
		$table            = self::table_name();
		self::clear_database_error( $wpdb );
		$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT dependency_type, dependency_id FROM {$table} WHERE parent_post_id = %d", $parent ), ARRAY_A );
		if ( self::database_failed( $wpdb ) ) {
			throw new \RuntimeException( 'Dependency drift edge query failed: ' . self::database_error( $wpdb ) );
		}
		$sets = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$sets[ (string) $row['dependency_type'] ][] = (int) $row['dependency_id'];
		}
		foreach ( $sets as &$ids ) {
			sort( $ids, SORT_NUMERIC );
			$ids = array_values( array_unique( $ids ) );
		}
		unset( $ids );
		return $sets;
	}

	/**
	 * Resolve the exact affiliate registry records a parent's content uses.
	 *
	 * Destinations are matched against every non-trash registry row (any
	 * lifecycle status) so status flips on a referenced merchant still
	 * invalidate the parent. Extraction failure falls back to binding every
	 * registry row: over-invalidation is safe, under-invalidation is not.
	 */
	private static function affiliate_ids_for_parent( int $parent ): array {
		if ( '1' !== (string) get_post_meta( $parent, '_lel_has_affiliate_links', true ) ) {
			return array();
		}
		$registry_ids = self::affiliate_registry_ids();
		if ( array() === $registry_ids ) {
			return array();
		}
		$content  = (string) get_post_field( 'post_content', $parent );
		$resolved = Affiliate_Registry::resolve_merchant_edges( $content, $registry_ids );
		if ( null === $resolved ) {
			++self::$affiliate_stats['broad_fallbacks'];
			Logger::error( 'affiliate_edge_extraction_failed_broad_binding', array( 'parent_post_id' => $parent ) );
			return $registry_ids;
		}
		self::$affiliate_stats['unresolved'] += $resolved['unresolved'];
		self::$affiliate_stats['ambiguous']  += $resolved['ambiguous'];
		if ( $resolved['unresolved'] > 0 || $resolved['ambiguous'] > 0 ) {
			Logger::info(
				'affiliate_edge_resolution_incomplete',
				array(
					'parent_post_id' => $parent,
					'unresolved'     => $resolved['unresolved'],
					'ambiguous'      => $resolved['ambiguous'],
				)
			);
		}
		return $resolved['edges'];
	}

	/** All non-trash affiliate registry record IDs, fail-closed on DB errors. */
	private static function affiliate_registry_ids(): array {
		global $wpdb;
		self::clear_database_error( $wpdb );
		$ids              = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'lel_affiliate' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC" );
		if ( self::database_failed( $wpdb ) ) {
			throw new \RuntimeException( 'Affiliate dependency query failed: ' . self::database_error( $wpdb ) );
		}
		return array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	/** Persist an option while distinguishing a no-op from a failed write. */
	private static function write_option( string $name, $value ): void {
		if ( ! update_option( $name, $value, false ) && get_option( $name, null ) !== $value ) {
			throw new \RuntimeException( 'Could not persist dependency backfill option ' . $name . '.' );
		}
	}

	/** Bounded database error with a stable fallback. */
	private static function database_error( $wpdb ): string {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		return '' === $error ? 'unknown_database_error' : substr( $error, 0, 200 );
	}

	/** Clear stale wpdb error state before a read. */
	private static function clear_database_error( $wpdb ): void {
		$wpdb->last_error = '';
	}

	/** Whether the latest wpdb operation reported an error. */
	private static function database_failed( $wpdb ): bool {
		return '' !== trim( (string) ( $wpdb->last_error ?? '' ) );
	}

	/**
	 * Find all parent post IDs that depend on a given entity.
	 *
	 * @param string $type   Dependency type.
	 * @param int    $dep_id The dependency post/user ID.
	 * @return list<int> Parent post IDs.
	 */
	public static function find_parents( string $type, int $dep_id ): array {
		global $wpdb;
		if ( $dep_id <= 0 ) {
			return array();
		}
		$ids = array();
		if ( self::exists() ) {
			$table = self::table_name();
			self::clear_database_error( $wpdb );
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT parent_post_id FROM {$table} WHERE dependency_type = %s AND dependency_id = %d",
					$type,
					$dep_id
				)
			);
			if ( self::database_failed( $wpdb ) ) {
				throw new \RuntimeException( 'Dependency parent lookup failed: ' . self::database_error( $wpdb ) );
			}
		}

		// The materialized index is only an accelerator: always union the
		// authoritative relationship so a partial/stale index cannot hide work.
		$ids = array_merge( is_array( $ids ) ? $ids : array(), self::authoritative_parents( $type, $dep_id ) );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Find all parent post IDs that depend on any of the given entities.
	 *
	 * @param string   $type    Dependency type.
	 * @param list<int> $dep_ids The dependency post/user IDs.
	 * @return list<int> Unique parent post IDs.
	 */
	public static function find_parents_batch( string $type, array $dep_ids ): array {
		$parents = array();
		foreach ( array_unique( array_map( 'intval', $dep_ids ) ) as $dep_id ) {
			$parents = array_merge( $parents, self::find_parents( $type, $dep_id ) );
		}
		$parents = array_values( array_unique( $parents ) );
		sort( $parents, SORT_NUMERIC );
		return $parents;
	}

	/** Resolve reverse relationships from canonical posts/meta, never the index. */
	private static function authoritative_parents( string $type, int $dep_id ): array {
		if ( 'lel_claim' === $type ) {
			$parent = (int) get_post_meta( $dep_id, 'post_id', true );
			return $parent > 0 ? array( $parent ) : array();
		}
		if ( 'lel_source' === $type ) {
			$parents = array();
			foreach ( self::governed_ids( array( 'lel_claim' ), 'source_id', (string) $dep_id ) as $claim_id ) {
				$parent = (int) get_post_meta( $claim_id, 'post_id', true );
				if ( $parent > 0 ) {
					$parents[] = $parent;
				}
			}
			return $parents;
		}
		if ( 'lel_test_record' === $type ) {
			return self::governed_ids( array( 'post', 'review' ), 'test_record_id', (string) $dep_id );
		}
		if ( 'lel_protocol' === $type ) {
			$parents = array();
			foreach ( self::governed_ids( array( 'lel_test_record' ), 'protocol_id', (string) $dep_id ) as $record_id ) {
				$parents = array_merge( $parents, self::governed_ids( array( 'post', 'review' ), 'test_record_id', (string) $record_id ) );
			}
			return $parents;
		}
		if ( 'credential' === $type ) {
			return self::governed_ids( array( 'post', 'review' ), 'medical_reviewer_user_id', (string) $dep_id );
		}
		if ( 'lel_affiliate' === $type ) {
			$parents = array();
			foreach ( self::governed_ids( array( 'post', 'review' ), '_lel_has_affiliate_links', '1' ) as $parent_id ) {
				$content  = (string) get_post_field( 'post_content', $parent_id );
				$resolved = Affiliate_Registry::resolve_merchant_edges( $content, array( $dep_id ) );
				// Extraction failure keeps the parent: over-invalidation is safe.
				if ( null === $resolved || array() !== $resolved['edges'] ) {
					$parents[] = $parent_id;
				}
			}
			return $parents;
		}
		return array();
	}

	/** Complete authoritative lookup that turns database failure into fail-closed. */
	private static function governed_ids( array $post_types, string $meta_key, string $meta_value ): array {
		global $wpdb;
		self::clear_database_error( $wpdb );
		$ids = Governed_Query::ids_by_meta( $post_types, $meta_key, $meta_value );
		if ( self::database_failed( $wpdb ) ) {
			throw new \RuntimeException( 'Authoritative dependency lookup failed: ' . self::database_error( $wpdb ) );
		}
		return array_map( 'intval', $ids );
	}
}
