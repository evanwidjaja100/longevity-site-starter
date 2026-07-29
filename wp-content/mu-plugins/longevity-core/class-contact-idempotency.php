<?php
/**
 * Atomic contact-submission idempotency reservations.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Authoritative custom table; the name derives only from the trusted WordPress prefix.
/**
 * A unique-key reservation table is the atomic persistence boundary that keeps
 * two concurrent identical contact requests to a single accepted aggregate and
 * a single notification. Only a hash and bookkeeping columns are stored: the
 * raw message and email never enter this table.
 */
final class Contact_Idempotency {
	public const SCHEMA_VERSION  = 1;
	public const STATE_PROCESSING = 'processing';
	public const STATE_COMPLETED  = 'completed';
	public const STATE_FAILED     = 'failed';

	private const LEASE_SECONDS = 120;
	private const STUCK_SECONDS = 900;

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_contact_idempotency' : 'wp_lel_contact_idempotency';
	}

	/** Additive schema install. */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_key_hash char(64) NOT NULL,
			state varchar(16) NOT NULL DEFAULT 'processing',
			message_post_id bigint(20) unsigned DEFAULT NULL,
			lease_expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at datetime DEFAULT NULL,
			schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY request_key_hash (request_key_hash),
			KEY state_lease (state,lease_expires_at)
		) {$charset};";
		dbDelta( $sql );
	}

	/** Whether the reservation table exists (never used on the request path). */
	public static function exists(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$table = self::table_name();
		self::clear_db_error();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( false === $found || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'exists' );
			return false;
		}
		return $table === $found;
	}

	/** Non-reversible reservation key derived from the validated request UUID. */
	public static function key_hash( string $request_uuid ): string {
		return hash( 'sha256', 'lel_contact|' . strtolower( $request_uuid ) );
	}

	/**
	 * Attempt to own a reservation for a request key.
	 *
	 * @param string $key SHA-256 request key hash.
	 * @return array{state:string, post_id:int}
	 */
	public static function reserve( string $key ): array {
		global $wpdb;
		$now   = gmdate( 'Y-m-d H:i:s' );
		$lease = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );
		self::clear_db_error();
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table_name() . ' (request_key_hash, state, message_post_id, lease_expires_at, created_at, updated_at, completed_at, schema_version) VALUES (%s, \'processing\', NULL, %s, %s, %s, NULL, %d)',
				$key,
				$lease,
				$now,
				$now,
				self::SCHEMA_VERSION
			)
		);
		if ( 1 === (int) $inserted && '' === (string) $wpdb->last_error ) {
			return array( 'state' => 'reserved', 'post_id' => 0 );
		}
		if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
			return self::resolve_existing( $key );
		}
		self::report_sql_failure( 'reserve' );
		return array( 'state' => 'unavailable', 'post_id' => 0 );
	}

	/** Resolve the state of an already-present reservation. */
	private static function resolve_existing( string $key ): array {
		$row = self::find( $key );
		if ( ! is_array( $row ) ) {
			return array( 'state' => 'unavailable', 'post_id' => 0 );
		}
		$state   = (string) ( $row['state'] ?? '' );
		$post_id = (int) ( $row['message_post_id'] ?? 0 );
		if ( self::STATE_COMPLETED === $state ) {
			return array( 'state' => 'completed', 'post_id' => $post_id );
		}
		if ( self::STATE_PROCESSING === $state && ! self::lease_expired( $row ) ) {
			return array( 'state' => 'in_progress', 'post_id' => $post_id );
		}
		$reclaimed = self::reclaim( $key );
		if ( 1 === $reclaimed ) {
			if ( self::STATE_PROCESSING === $state && $post_id > 0 ) {
				return array( 'state' => 'resume', 'post_id' => $post_id );
			}
			return array( 'state' => 'reclaimed', 'post_id' => 0 );
		}
		if ( false === $reclaimed ) {
			return array( 'state' => 'unavailable', 'post_id' => 0 );
		}
		$row = self::find( $key );
		if ( is_array( $row ) && self::STATE_COMPLETED === (string) ( $row['state'] ?? '' ) ) {
			return array( 'state' => 'completed', 'post_id' => (int) ( $row['message_post_id'] ?? 0 ) );
		}
		return array( 'state' => 'in_progress', 'post_id' => $post_id );
	}

	/** One-winner conditional reclaim of an expired-processing or failed row. */
	private static function reclaim( string $key ): int|false {
		global $wpdb;
		$now   = gmdate( 'Y-m-d H:i:s' );
		$lease = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );
		self::clear_db_error();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . " SET state = 'processing', lease_expires_at = %s, updated_at = %s WHERE request_key_hash = %s AND ((state = 'processing' AND lease_expires_at < %s) OR state = 'failed')",
				$lease,
				$now,
				$key,
				$now
			)
		);
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'reclaim' );
			return false;
		}
		return (int) $updated;
	}

	/** Link the created aggregate to a live processing reservation. */
	public static function link_post( string $key, int $post_id ): bool {
		global $wpdb;
		if ( $post_id < 1 ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		self::clear_db_error();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . " SET message_post_id = %d, updated_at = %s WHERE request_key_hash = %s AND state = 'processing'",
				$post_id,
				$now,
				$key
			)
		);
		if ( 1 !== (int) $updated || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'link_post' );
			return false;
		}
		return true;
	}

	/** Mark a reservation completed against its aggregate. */
	public static function complete( string $key, int $post_id ): bool {
		global $wpdb;
		if ( $post_id < 1 ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		self::clear_db_error();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . " SET state = 'completed', message_post_id = %d, completed_at = %s, lease_expires_at = NULL, updated_at = %s WHERE request_key_hash = %s AND state = 'processing'",
				$post_id,
				$now,
				$now,
				$key
			)
		);
		if ( 1 !== (int) $updated || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'complete' );
			return false;
		}
		return true;
	}

	/** Mark a reservation failed and reclaimable; drop any post linkage. */
	public static function mark_failed( string $key ): bool {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		self::clear_db_error();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . " SET state = 'failed', message_post_id = NULL, lease_expires_at = NULL, updated_at = %s WHERE request_key_hash = %s AND state = 'processing'",
				$now,
				$key
			)
		);
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'mark_failed' );
			return false;
		}
		return (int) $updated >= 1;
	}

	/** Purge terminal reservations older than the cutoff; live ones are kept. */
	public static function purge_terminal( int $older_than_seconds ): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $older_than_seconds ) );
		self::clear_db_error();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table_name() . " WHERE state IN ('completed','failed') AND updated_at < %s",
				$cutoff
			)
		);
		if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'purge_terminal' );
			return 0;
		}
		return (int) $deleted;
	}

	/** Availability + per-state counts + stuck-processing observability. */
	public static function stats(): array {
		global $wpdb;
		if ( ! self::exists() ) {
			return array(
				'available'  => false,
				'error'      => '' !== (string) $wpdb->last_error ? 'database_error' : 'table_missing',
				'processing' => null,
				'completed'  => null,
				'failed'     => null,
				'stuck'      => 0,
			);
		}
		$processing = self::count( "state = 'processing'" );
		$completed  = self::count( "state = 'completed'" );
		$failed     = self::count( "state = 'failed'" );
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_SECONDS );
		$stuck      = self::count( $wpdb->prepare( "state = 'processing' AND lease_expires_at < %s", $cutoff ) );
		if ( in_array( null, array( $processing, $completed, $failed, $stuck ), true ) ) {
			return array(
				'available'  => false,
				'error'      => 'query_failed',
				'processing' => null,
				'completed'  => null,
				'failed'     => null,
				'stuck'      => 0,
			);
		}
		return array(
			'available'  => true,
			'error'      => '',
			'processing' => $processing,
			'completed'  => $completed,
			'failed'     => $failed,
			'stuck'      => $stuck,
		);
	}

	/** Count rows matching a trusted static WHERE fragment; null on failure. */
	private static function count( string $where ): ?int {
		global $wpdb;
		self::clear_db_error();
		$value = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . $where );
		if ( null === $value || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'count' );
			return null;
		}
		return (int) $value;
	}

	/** Read one reservation row; false on a read failure. */
	private static function find( string $key ): array|false|null {
		global $wpdb;
		self::clear_db_error();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE request_key_hash = %s LIMIT 1', $key ),
			ARRAY_A
		);
		if ( false === $row || ( null === $row && '' !== (string) $wpdb->last_error ) ) {
			self::report_sql_failure( 'find' );
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/** Whether a row's processing lease has elapsed. */
	private static function lease_expired( array $row ): bool {
		$lease = (string) ( $row['lease_expires_at'] ?? '' );
		if ( '' === $lease ) {
			return true;
		}
		return strtotime( $lease . ' UTC' ) < time();
	}

	/** Reset wpdb's operation-local error before authoritative SQL. */
	private static function clear_db_error(): void {
		global $wpdb;
		$wpdb->last_error = '';
	}

	/** Bounded, non-PII operator-visible SQL failure log. */
	private static function report_sql_failure( string $operation ): void {
		global $wpdb;
		Logger::error(
			'contact_idempotency_sql_failed',
			array(
				'operation' => $operation,
				'db_error'  => substr( (string) $wpdb->last_error, 0, 160 ),
			)
		);
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
