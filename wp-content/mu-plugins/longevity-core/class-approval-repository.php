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
			audit_event_id bigint(20) unsigned NULL,
			activated_at datetime NULL,
			activation_error varchar(255) NULL,
			PRIMARY KEY  (id),
			KEY post_type_status (post_id,approval_type,approval_status),
			KEY post_approved (post_id,approved_at),
			KEY combined_hash (combined_hash),
			KEY approver_approved (approver_user_id,approved_at),
			KEY approval_state (approval_status,id),
			KEY approval_audit (approval_status,audit_event_id)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Add the PR-02 activation columns and indexes to an already-installed table.
	 *
	 * dbDelta does not reliably add columns or keys in place, so this issues
	 * explicit, idempotent ALTERs. Safe to call repeatedly.
	 */
	public static function ensure_activation_columns(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! self::exists() ) {
			return false;
		}
		$table   = self::table_name();
		$columns = array(
			'audit_event_id'   => 'bigint(20) unsigned NULL',
			'activated_at'     => 'datetime NULL',
			'activation_error' => 'varchar(255) NULL',
		);
		foreach ( $columns as $column => $definition ) {
			$present = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, $column ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; column and definition are hardcoded constants above.
			if ( $present <= 0 && false === $wpdb->query( "ALTER TABLE {$table} ADD {$column} {$definition}" ) ) {
				return false;
			}
		}
		$indexes = array(
			'approval_state' => 'approval_status,id',
			'approval_audit' => 'approval_status,audit_event_id',
		);
		foreach ( $indexes as $index => $definition ) {
			$present = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s', $table, $index ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; index and definition are hardcoded constants above.
			if ( $present <= 0 && false === $wpdb->query( "ALTER TABLE {$table} ADD KEY {$index} ({$definition})" ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Mark pre-PR-02 approved rows as legacy so they are not treated as orphaned.
	 *
	 * These rows predate mandatory audit linkage; they carry no audit_event_id
	 * but are historically valid. Recording activation_error='legacy_pre_pr02'
	 * (with activated_at set from approved_at) preserves them as current while
	 * excluding them from orphan detection. No rows are deleted.
	 */
	public static function backfill_legacy_activation(): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		$table = self::table_name();
		return (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values are hardcoded literals.
			"UPDATE {$table} SET activation_error = 'legacy_pre_pr02', activated_at = approved_at WHERE approval_status = 'approved' AND audit_event_id IS NULL AND activation_error IS NULL"
		);
	}

	/** Insert a snapshot. */
	public static function insert( array $record ): int {
		global $wpdb;
		$inserted = $wpdb->insert( self::table_name(), $record );
		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Atomically activate a pending snapshot once its audit event is durable.
	 *
	 * The conditional WHERE clause is the sole activation gate: the row must
	 * still be pending_audit and its combined fingerprint must be unchanged, so
	 * a concurrent invalidation or a mismatched request can never be promoted.
	 *
	 * @return int Rows affected (1 on success, 0 when the guard did not match).
	 */
	public static function activate( int $id, string $combined_hash, int $audit_event_id ): int {
		global $wpdb;
		$table = self::table_name();
		$sql   = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values use prepare() placeholders.
			"UPDATE {$table} SET approval_status = 'approved', audit_event_id = %d, activated_at = %s, activation_error = NULL WHERE id = %d AND approval_status = 'pending_audit' AND combined_hash = %s",
			$audit_event_id,
			gmdate( 'Y-m-d H:i:s' ),
			$id,
			$combined_hash
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fully prepared above.
		return (int) $wpdb->query( $sql );
	}

	/** Close an unusable pending snapshot so it can never become current. */
	public static function reject_pending( int $id, string $reason ): int {
		global $wpdb;
		$table = self::table_name();
		$sql   = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values use prepare() placeholders.
			"UPDATE {$table} SET approval_status = 'rejected', activation_error = %s WHERE id = %d AND approval_status = 'pending_audit'",
			substr( sanitize_text_field( $reason ), 0, 255 ),
			$id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fully prepared above.
		return (int) $wpdb->query( $sql );
	}

	/** Record why a pending snapshot could not activate without approving it. */
	public static function note_activation_error( int $id, string $reason ): int {
		global $wpdb;
		$table = self::table_name();
		$sql   = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values use prepare() placeholders.
			"UPDATE {$table} SET activation_error = %s WHERE id = %d AND approval_status = 'pending_audit'",
			substr( sanitize_text_field( $reason ), 0, 255 ),
			$id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fully prepared above.
		return (int) $wpdb->query( $sql );
	}

	/**
	 * Keyset batch of pending snapshots for bounded reconciliation.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function pending_batch( int $after_id, int $limit ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 500, $limit ) );
		$sql   = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values use prepare() placeholders.
			"SELECT * FROM {$table} WHERE approval_status = 'pending_audit' AND id > %d ORDER BY id ASC LIMIT %d",
			$after_id,
			$limit
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fully prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Count approved rows that lack any mandatory-audit linkage (true PR-02 orphans). */
	public static function count_orphaned_approved(): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		$table = self::table_name();
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values are hardcoded literals.
			"SELECT COUNT(1) FROM {$table} WHERE approval_status = 'approved' AND invalidated_at IS NULL AND audit_event_id IS NULL AND activation_error IS NULL"
		);
	}

	/** Count pending snapshots older than the given age in seconds. */
	public static function count_stale_pending( int $max_age_seconds ): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		$table     = self::table_name();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - max( 0, $max_age_seconds ) );
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; the value uses a prepare() placeholder.
			$wpdb->prepare( "SELECT COUNT(1) FROM {$table} WHERE approval_status = 'pending_audit' AND approved_at < %s", $threshold )
		);
	}

	/** Total count of pending snapshots regardless of age. */
	public static function count_pending(): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values are hardcoded literals.
		return (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$table} WHERE approval_status = 'pending_audit'" );
	}

	/** Get the newest non-invalidated snapshot. */
	public static function current( int $post_id, string $approval_type ): ?array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values use prepare() placeholders.
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d AND approval_type = %s AND approval_status = 'approved' AND invalidated_at IS NULL ORDER BY id DESC LIMIT 1", $post_id, $approval_type );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fully prepared above.
		$row   = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Invalidate every current snapshot of a type. */
	public static function invalidate( int $post_id, string $approval_type, string $reason, int $actor_id ): int {
		global $wpdb;
		$table = self::table_name();
		$sql   = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values use prepare() placeholders.
			"UPDATE {$table} SET invalidated_at = %s, invalidated_by_user_id = %d, invalidation_reason = %s WHERE post_id = %d AND approval_type = %s AND invalidated_at IS NULL",
			gmdate( 'Y-m-d H:i:s' ),
			$actor_id,
			substr( sanitize_text_field( $reason ), 0, 255 ),
			$post_id,
			$approval_type
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fully prepared above.
		return (int) $wpdb->query( $sql );
	}

	/** Whether the table exists. */
	public static function exists(): bool {
		global $wpdb;
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
