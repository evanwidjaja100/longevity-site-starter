<?php
/**
 * Append-only governance audit events.
 *
 * Sequence allocation uses a dedicated singleton row for atomicity.
 * Writes are retried on transient failures and failures are observable.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Records bounded, sanitized, hash-chained governance events. */
final class Audit_Log {
	public const SCHEMA_VERSION = '1.3.0';

	/** Maximum retry attempts for transient DB failures. */
	private const MAX_RETRIES = 3;

	/** Maximum retry sleep, in microseconds. */
	private const MAX_RETRY_DELAY = 400000;

	/** When true, record() returns a positive ID without writing (test mode). */
	private static bool $test_mode = false;

	/** @var list<string> Event types that simulate a write failure while in test mode. */
	private static array $test_fail_events = array();

	/** @var list<array<string, mixed>> Events captured while in test mode. */
	private static array $test_events = array();

	/** A failed COMMIT has an unknown outcome; no later governance write is safe. */
	private static string $unhealthy_reason = '';

	/** Enable test mode: record() returns a positive ID without writing. */
	public static function set_test_mode( bool $enabled ): void {
		self::$test_mode        = $enabled;
		self::$test_events      = array();
		self::$test_fail_events = array();
		self::$unhealthy_reason = '';
	}

	/**
	 * Simulate write failures for specific event types while in test mode.
	 *
	 * @param list<string> $event_types Event types that must fail.
	 */
	public static function set_test_fail_events( array $event_types ): void {
		self::$test_fail_events = $event_types;
	}

	/** Events captured while in test mode, in record() call order. */
	public static function test_events(): array {
		return self::$test_events;
	}

	/** Clear captured test-mode events. */
	public static function reset_test_events(): void {
		self::$test_events = array();
	}

	/** Whether governance writes may continue in this request. */
	public static function request_is_healthy(): bool {
		return '' === self::$unhealthy_reason;
	}

	/** Why this request was quarantined from further governance writes. */
	public static function unhealthy_reason(): string {
		return self::$unhealthy_reason;
	}

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_audit_events' : 'wp_lel_audit_events';
	}

	/** Sequence allocator table name. */
	public static function sequence_table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_audit_sequence' : 'wp_lel_audit_sequence';
	}

	/** Install additive tables with unique sequence constraint and sequence allocator. */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sequence bigint(20) unsigned NOT NULL,
			occurred_at datetime NOT NULL,
			event_type varchar(64) NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			request_id varchar(64) NOT NULL,
			source_channel varchar(32) NOT NULL,
			payload_json text NOT NULL,
			previous_event_hash char(64) NOT NULL,
			event_hash char(64) NOT NULL,
			idempotency_key char(64) DEFAULT NULL,
			schema_version varchar(20) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sequence (sequence),
			UNIQUE KEY previous_event_hash (previous_event_hash),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY object_time (object_type,object_id,occurred_at),
			KEY actor_time (actor_user_id,occurred_at),
			KEY event_type (event_type)
		) ENGINE=InnoDB {$charset};";
		dbDelta( $sql );

		$seq_table = self::sequence_table_name();
		$seq_sql   = "CREATE TABLE {$seq_table} (
			id tinyint(1) unsigned NOT NULL DEFAULT 1,
			current_value bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) ENGINE=InnoDB {$charset};";
		dbDelta( $seq_sql );
		if ( ! self::exists() || $seq_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $seq_table ) ) ) {
			throw new \RuntimeException( 'Audit tables were not created.' );
		}

		// Ensure the singleton row exists.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values are hardcoded literals.
		if ( false === $wpdb->query( "INSERT IGNORE INTO {$seq_table} (id, current_value) VALUES (1, 0)" ) ) {
			throw new \RuntimeException( esc_html( 'Could not initialize the audit sequence allocator: ' . self::database_error( $wpdb ) ) );
		}
	}

	/**
	 * Ensure the fork-preventing constraints exist on an already-installed table.
	 *
	 * dbDelta does not reliably add UNIQUE keys to existing tables, so this
	 * explicitly enforces InnoDB and the unique previous_event_hash index that
	 * makes chain forks impossible (two events cannot share a predecessor).
	 * Idempotent and safe to call repeatedly.
	 *
	 * @return bool True when the constraint is present after the call.
	 */
	public static function ensure_fork_constraint(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! self::exists() ) {
			return false;
		}
		$table = self::table_name();

		// Enforce InnoDB (required for the FOR UPDATE row locking used by writes).
		$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		if ( '' !== $engine && 0 !== strcasecmp( $engine, 'InnoDB' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; no user data in the query.
			if ( false === $wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" ) ) {
				return false;
			}
		}

		$has_index = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0', $table, 'previous_event_hash' ) );
		if ( $has_index > 0 ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; no user data in the query.
		if ( false === $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY previous_event_hash (previous_event_hash)" ) ) {
			return false;
		}
		$has_index = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0', $table, 'previous_event_hash' ) );
		return $has_index > 0;
	}

	/** Ensure the nullable unique key used by idempotent outbox delivery. */
	public static function ensure_idempotency_constraint(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! self::exists() ) {
			return false;
		}
		$table = self::table_name();
		$column = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, 'idempotency_key' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; no user data in the query.
		if ( $column <= 0 && false === $wpdb->query( "ALTER TABLE {$table} ADD idempotency_key char(64) DEFAULT NULL" ) ) {
			return false;
		}
		$unique = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0', $table, 'idempotency_key' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; no user data in the query.
		if ( $unique <= 0 && false === $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY idempotency_key (idempotency_key)" ) ) {
			return false;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0', $table, 'idempotency_key' ) ) > 0;
	}

	/**
	 * Record an event. Sensitive text is never accepted wholesale.
	 *
	 * @param bool $mandatory When true, failure throws an exception (fail-closed for state transitions).
	 * @return int Insert ID on success, 0 on non-mandatory failure.
	 * @throws \RuntimeException When $mandatory is true and the write fails after retries.
	 */
	public static function record( string $event_type, string $object_type, int $object_id, array $payload = array(), int $actor_id = 0, string $source_channel = 'system', bool $mandatory = false, string $idempotency_key = '' ): int {
		global $wpdb;
		$event_type     = substr( sanitize_key( $event_type ), 0, 64 );
		$object_type    = substr( sanitize_key( $object_type ), 0, 40 );
		$source_channel = substr( sanitize_key( $source_channel ), 0, 32 );
		$payload        = self::sanitize_payload( $payload );
		$request_id     = self::request_id();
		$idempotency_key = '' === $idempotency_key ? '' : hash( 'sha256', $idempotency_key );
		if ( ! self::request_is_healthy() ) {
			$message = 'Governance write blocked because this request has an unknown database outcome: ' . self::$unhealthy_reason;
			if ( $mandatory ) {
				throw new \RuntimeException( esc_html( $message ) );
			}
			return 0;
		}
		if ( self::$test_mode ) {
			if ( in_array( $event_type, self::$test_fail_events, true ) ) {
				if ( $mandatory ) {
					throw new \RuntimeException( esc_html( sprintf( 'Simulated mandatory audit failure for %s (test mode).', $event_type ) ) );
				}
				return 0;
			}
			self::$test_events[] = array(
				'event_type'  => $event_type,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'payload'     => $payload,
				'actor_id'    => $actor_id,
				'channel'     => $source_channel,
				'mandatory'   => $mandatory,
			);
			return count( self::$test_events );
		}
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'insert' ) || ! self::exists() ) {
			self::record_failure( $event_type, 'table_unavailable' );
			if ( $mandatory ) {
				throw new \RuntimeException( 'Audit table unavailable and event is mandatory.' );
			}
			return 0;
		}

		$last_error = '';
		$attempts   = 0;
		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; ++$attempt ) {
			$attempts = $attempt;
			try {
				$result = self::attempt_write( $wpdb, $event_type, $object_type, $object_id, $payload, $actor_id, $source_channel, $request_id, $idempotency_key );
			} catch ( \Throwable $error ) {
				$last_error = $error->getMessage();
				if ( $attempt < self::MAX_RETRIES && self::is_transient_error( $last_error ) && self::request_is_healthy() ) {
					self::retry_sleep( $attempt );
					continue;
				}
				break;
			}
			if ( $result > 0 ) {
				return $result;
			}
			$last_error = (string) ( $wpdb->last_error ?? 'unknown' );
			if ( ! self::request_is_healthy() ) {
				break;
			}
			// Retry only on transient failures (duplicate key, deadlock, lock wait timeout).
			if ( ! self::is_transient_error( $last_error ) ) {
				break;
			}
			if ( $attempt < self::MAX_RETRIES ) {
				self::retry_sleep( $attempt );
			}
		}

		self::record_failure( $event_type, $last_error );
		if ( $mandatory ) {
			throw new \RuntimeException( esc_html( sprintf( 'Mandatory audit write failed after %d attempt(s): %s', $attempts, $last_error ) ) );
		}
		return 0;
	}

	/**
	 * Verify chain integrity using keyset pagination: detect gaps, forks, mutations, and invalid predecessors.
	 *
	 * @param int $batch_size Number of rows to verify per batch.
	 * @return array{valid: bool, checked: int, errors: list<string>}
	 */
	public static function verify_chain( int $batch_size = 500 ): array {
		global $wpdb;
		$errors = array();
		$checked = 0;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! self::exists() ) {
			return array( 'valid' => false, 'checked' => 0, 'errors' => array( 'audit_table_unavailable' ) );
		}
		$table     = self::table_name();
		$last_seq  = 0;
		$prev_hash = '';
		$prev_seq  = 0;
		while ( true ) {
			self::clear_database_error( $wpdb );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values use prepare() placeholders.
			$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE sequence > %d ORDER BY sequence ASC LIMIT %d", $last_seq, $batch_size ), ARRAY_A );
			if ( self::database_failed( $wpdb ) ) {
				$errors[] = 'audit_read_failed:' . substr( (string) $wpdb->last_error, 0, 120 );
				break;
			}
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				++$checked;
				$seq = (int) $row['sequence'];
				if ( $prev_seq > 0 && $seq !== $prev_seq + 1 ) {
					$errors[] = sprintf( 'gap_at_sequence_%d_expected_%d', $seq, $prev_seq + 1 );
				}
				if ( (string) $row['previous_event_hash'] !== $prev_hash ) {
					$errors[] = sprintf( 'predecessor_mismatch_at_sequence_%d', $seq );
				}
				$recomputed = self::recompute_hash( $row );
				if ( ! hash_equals( (string) $row['event_hash'], $recomputed ) ) {
					$errors[] = sprintf( 'hash_mutation_at_sequence_%d', $seq );
				}
				$prev_hash = (string) $row['event_hash'];
				$prev_seq  = $seq;
			}
			$last_seq = $prev_seq;
		}
		return array( 'valid' => empty( $errors ), 'checked' => $checked, 'errors' => array_slice( $errors, 0, 50 ) );
	}

	/** Recompute the event hash from stored row data for tamper detection. */
	private static function recompute_hash( array $row ): string {
		$record = array(
			'sequence'            => (int) $row['sequence'],
			'occurred_at'         => (string) $row['occurred_at'],
			'event_type'          => (string) $row['event_type'],
			'actor_user_id'       => (int) $row['actor_user_id'],
			'object_type'         => (string) $row['object_type'],
			'object_id'           => (int) $row['object_id'],
			'request_id'          => (string) $row['request_id'],
			'source_channel'      => (string) $row['source_channel'],
			'payload_json'        => (string) $row['payload_json'],
			'previous_event_hash' => (string) $row['previous_event_hash'],
		);
		if ( version_compare( (string) $row['schema_version'], '1.3.0', '>=' ) ) {
			$record['idempotency_key'] = null === ( $row['idempotency_key'] ?? null ) ? null : (string) $row['idempotency_key'];
		}
		$record['schema_version'] = (string) $row['schema_version'];
		return hash( 'sha256', Approval_Fingerprint::canonical_json( $record ) );
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

	/** Get the current audit write failure count. */
	public static function failure_count(): int {
		return (int) get_option( 'lel_audit_write_failures', 0 );
	}

	/**
	 * Locate a durably committed event by its raw idempotency key.
	 *
	 * Reconciliation uses this to confirm that a pending approval's mandatory
	 * audit event actually committed. The raw key is hashed the same way
	 * record() hashes it before storage.
	 *
	 * @return array{id:int, event_type:string, object_type:string, object_id:int}|null
	 */
	public static function event_for_idempotency_key( string $raw_key ): ?array {
		global $wpdb;
		if ( '' === $raw_key || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! self::exists() ) {
			return null;
		}
		$hashed = hash( 'sha256', $raw_key );
		$table  = self::table_name();
		$row    = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; the value uses a prepare() placeholder.
			$wpdb->prepare( "SELECT id, event_type, object_type, object_id FROM {$table} WHERE idempotency_key = %s LIMIT 1", $hashed ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'event_type'  => (string) ( $row['event_type'] ?? '' ),
			'object_type' => (string) ( $row['object_type'] ?? '' ),
			'object_id'   => (int) ( $row['object_id'] ?? 0 ),
		);
	}

	/** Allocate the next sequence number atomically via the singleton row. */
	private static function allocate_sequence( $wpdb ): int {
		$seq_table = self::sequence_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values are hardcoded literals.
		$updated = $wpdb->query( "UPDATE {$seq_table} SET current_value = LAST_INSERT_ID(current_value + 1) WHERE id = 1" );
		if ( false === $updated ) {
			throw new \RuntimeException( esc_html( 'Audit sequence update failed: ' . self::database_error( $wpdb ) ) );
		}
		// wpdb does not refresh insert_id for UPDATE statements. Read the
		// connection-local value explicitly or an unrelated insert ID can become
		// the audit sequence and break the hash chain.
		$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		if ( 1 !== (int) $updated || $seq <= 0 ) {
			// Fallback: initialize the row if it does not exist yet.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values are hardcoded literals.
			if ( false === $wpdb->query( "INSERT IGNORE INTO {$seq_table} (id, current_value) VALUES (1, 0)" ) ) {
				throw new \RuntimeException( esc_html( 'Audit sequence initialization failed: ' . self::database_error( $wpdb ) ) );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; all values are hardcoded literals.
			$updated = $wpdb->query( "UPDATE {$seq_table} SET current_value = LAST_INSERT_ID(current_value + 1) WHERE id = 1" );
			if ( 1 !== (int) $updated ) {
				throw new \RuntimeException( esc_html( 'Audit sequence allocator row is unavailable: ' . self::database_error( $wpdb ) ) );
			}
			$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		}
		return $seq;
	}

	/**
	 * Attempt a single transactional write. Returns insert ID or 0.
	 *
	 * Every non-committed outcome — including COMMIT failure and mid-write
	 * exceptions — issues an explicit ROLLBACK so no connection is left
	 * holding an open transaction or gap lock.
	 */
	private static function attempt_write( $wpdb, string $event_type, string $object_type, int $object_id, array $payload, int $actor_id, string $source_channel, string $request_id, string $idempotency_key ): int {
		$table   = self::table_name();
		$started = $wpdb->query( 'START TRANSACTION' );
		if ( false === $started ) {
			return 0;
		}
		$committed = false;
		try {
			// The sequence row is the global append mutex. Take it before reading
			// the tail so concurrent writers cannot create next-key lock cycles on
			// the audit table's descending index scan.
			$next_seq = self::allocate_sequence( $wpdb );
			if ( $next_seq <= 0 ) {
				return 0;
			}
			if ( '' !== $idempotency_key ) {
				self::clear_database_error( $wpdb );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; the value uses a prepare() placeholder.
				$existing         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s LIMIT 1 FOR UPDATE", $idempotency_key ) );
				if ( self::database_failed( $wpdb ) ) {
					throw new \RuntimeException( 'Audit idempotency lookup failed: ' . self::database_error( $wpdb ) );
				}
				if ( $existing > 0 ) {
					// Roll back the unused sequence increment so replay creates no gap.
					if ( false === $wpdb->query( 'ROLLBACK' ) ) {
						self::mark_request_unhealthy( 'audit_idempotency_lookup_rollback_failed', $wpdb );
						return 0;
					}
					$committed = true;
					return $existing;
				}
			}
			self::clear_database_error( $wpdb );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; no user data in the query.
			$previous         = (string) $wpdb->get_var( "SELECT event_hash FROM {$table} ORDER BY sequence DESC LIMIT 1" );
			if ( self::database_failed( $wpdb ) ) {
				throw new \RuntimeException( 'Audit predecessor read failed: ' . self::database_error( $wpdb ) );
			}
			$record = array(
				'sequence'            => $next_seq,
				'occurred_at'         => gmdate( 'Y-m-d H:i:s' ),
				'event_type'          => $event_type,
				'actor_user_id'       => max( 0, $actor_id ),
				'object_type'         => $object_type,
				'object_id'           => max( 0, $object_id ),
				'request_id'          => $request_id,
				'source_channel'      => $source_channel,
				'payload_json'        => (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_event_hash' => $previous,
				'idempotency_key'     => '' === $idempotency_key ? null : $idempotency_key,
				'schema_version'      => self::SCHEMA_VERSION,
			);
			$record['event_hash'] = hash( 'sha256', Approval_Fingerprint::canonical_json( $record ) );
			if ( '' === $idempotency_key ) {
				unset( $record['idempotency_key'] );
			}
			$inserted             = $wpdb->insert( $table, $record );
			if ( false === $inserted ) {
				return 0;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				self::mark_request_unhealthy( 'audit_commit_outcome_unknown', $wpdb );
				return 0;
			}
			$committed = true;
			return (int) $wpdb->insert_id;
		} finally {
			if ( ! $committed ) {
				if ( false === $wpdb->query( 'ROLLBACK' ) ) {
					self::mark_request_unhealthy( 'audit_rollback_failed', $wpdb );
				}
			}
		}
	}

	/** Whether a DB error is transient and retryable. */
	private static function is_transient_error( string $error ): bool {
		if ( '' === $error ) {
			return false;
		}
		$transient_patterns = array( 'Duplicate entry', 'Deadlock found', 'Lock wait timeout', 'try restarting transaction' );
		foreach ( $transient_patterns as $pattern ) {
			if ( false !== stripos( $error, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/** Bounded exponential delay with jitter between transient attempts. */
	private static function retry_sleep( int $attempt ): void {
		$delay = min( self::MAX_RETRY_DELAY, 50000 * ( 2 ** max( 0, $attempt - 1 ) ) );
		usleep( $delay + wp_rand( 0, (int) ( $delay / 2 ) ) );
	}

	/** Record a write failure for observability. */
	private static function record_failure( string $event_type, string $reason ): void {
		$count = (int) get_option( 'lel_audit_write_failures', 0 );
		if ( ! update_option( 'lel_audit_write_failures', $count + 1, false ) && (int) get_option( 'lel_audit_write_failures', 0 ) !== $count + 1 ) {
			error_log( 'Longevity audit failure counter could not be persisted.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		Logger::error( 'audit_write_failure', array( 'event_type' => $event_type, 'reason' => substr( $reason, 0, 200 ) ) );
	}

	/** Quarantine the request after an unknown transaction outcome. */
	private static function mark_request_unhealthy( string $reason, $wpdb ): void {
		if ( '' === self::$unhealthy_reason ) {
			self::$unhealthy_reason = $reason . ':' . substr( self::database_error( $wpdb ), 0, 120 );
			Logger::error( 'governance_request_unhealthy', array( 'reason' => self::$unhealthy_reason, 'request_id' => self::request_id() ) );
		}
	}

	/** Current bounded database error, including a stable fallback. */
	private static function database_error( $wpdb ): string {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		return '' === $error ? 'unknown_database_error' : substr( $error, 0, 200 );
	}

	/** Clear stale wpdb error state before an operation that can validly return null. */
	private static function clear_database_error( $wpdb ): void {
		$wpdb->last_error = '';
	}

	/** Whether the most recent database operation reported an error. */
	private static function database_failed( $wpdb ): bool {
		return '' !== trim( (string) ( $wpdb->last_error ?? '' ) );
	}

	/** Stable request correlation ID for the current request. */
	private static function request_id(): string {
		return Logger::request_id();
	}

	/** Sanitize and bound nested payloads. */
	private static function sanitize_payload( array $payload ): array {
		$blocked = array( 'body', 'message', 'contact_email', 'contact_ip', 'credential_verification_evidence_ref', 'raw_observations', 'evidence_references' );
		$out     = array();
		foreach ( array_slice( $payload, 0, 20, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || in_array( $key, $blocked, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::sanitize_payload( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 255 );
			}
		}
		return $out;
	}
}
