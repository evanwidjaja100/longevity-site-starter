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
	public const SCHEMA_VERSION = '1.0.0';

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_dependencies' : 'wp_lel_dependencies';
	}

	/** Install the dependency index table (additive, idempotent). */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
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
	}

	/** Whether the table exists. */
	public static function exists(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
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
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (dependency_type, dependency_id, parent_post_id, created_at) VALUES (%s, %d, %d, NOW())",
				$type,
				$dep_id,
				$parent
			)
		);
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
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE dependency_type = %s AND dependency_id = %d AND parent_post_id = %d",
				$type,
				$dep_id,
				$parent
			)
		);
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
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE dependency_type = %s AND dependency_id = %d",
				$type,
				$dep_id
			)
		);
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
		if ( ! self::exists() || $dep_id <= 0 ) {
			return array();
		}
		$table = self::table_name();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT parent_post_id FROM {$table} WHERE dependency_type = %s AND dependency_id = %d",
				$type,
				$dep_id
			)
		);
		return array_map( 'intval', $ids ?: array() );
	}

	/**
	 * Find all parent post IDs that depend on any of the given entities.
	 *
	 * @param string   $type    Dependency type.
	 * @param list<int> $dep_ids The dependency post/user IDs.
	 * @return list<int> Unique parent post IDs.
	 */
	public static function find_parents_batch( string $type, array $dep_ids ): array {
		global $wpdb;
		if ( ! self::exists() || empty( $dep_ids ) ) {
			return array();
		}
		$table      = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $dep_ids ), '%d' ) );
		$ids        = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT parent_post_id FROM {$table} WHERE dependency_type = %s AND dependency_id IN ({$placeholders})",
				array_merge( array( $type ), $dep_ids )
			)
		);
		return array_map( 'intval', $ids ?: array() );
	}
}
