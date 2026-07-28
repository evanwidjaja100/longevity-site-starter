<?php
/**
 * Approval snapshot persistence.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Append-oriented repository for immutable approval snapshots. */
final class Approval_Repository {
	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_approval_snapshots' : 'wp_lel_approval_snapshots';
	}

	/** Create or update the additive table. */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			approval_type varchar(40) NOT NULL,
			approval_status varchar(24) NOT NULL,
			revision_id bigint(20) unsigned NULL,
			content_hash char(64) NOT NULL,
			governed_meta_hash char(64) NOT NULL,
			dependency_hash char(64) NOT NULL,
			combined_hash char(64) NOT NULL,
			approver_user_id bigint(20) unsigned NOT NULL,
			approved_at datetime NOT NULL,
			schema_version varchar(20) NOT NULL,
			payload_json longtext NOT NULL,
			invalidated_at datetime NULL,
			invalidated_by_user_id bigint(20) unsigned NULL,
			invalidation_reason varchar(255) NULL,
			supersedes_approval_id bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY post_type_status (post_id,approval_type,approval_status),
			KEY post_approved (post_id,approved_at),
			KEY combined_hash (combined_hash),
			KEY approver_approved (approver_user_id,approved_at)
		) {$charset};";
		dbDelta( $sql );
	}

	/** Insert a snapshot. */
	public static function insert( array $record ): int {
		global $wpdb;
		$inserted = $wpdb->insert( self::table_name(), $record );
		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/** Get the newest non-invalidated snapshot. */
	public static function current( int $post_id, string $approval_type ): ?array {
		global $wpdb;
		$table = self::table_name();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d AND approval_type = %s AND approval_status = 'approved' AND invalidated_at IS NULL ORDER BY id DESC LIMIT 1", $post_id, $approval_type );
		$row   = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Invalidate every current snapshot of a type. */
	public static function invalidate( int $post_id, string $approval_type, string $reason, int $actor_id ): int {
		global $wpdb;
		$table = self::table_name();
		$sql   = $wpdb->prepare(
			"UPDATE {$table} SET invalidated_at = %s, invalidated_by_user_id = %d, invalidation_reason = %s WHERE post_id = %d AND approval_type = %s AND invalidated_at IS NULL",
			gmdate( 'Y-m-d H:i:s' ),
			$actor_id,
			substr( sanitize_text_field( $reason ), 0, 255 ),
			$post_id,
			$approval_type
		);
		return (int) $wpdb->query( $sql );
	}

	/** Whether the table exists. */
	public static function exists(): bool {
		global $wpdb;
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
