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
	public const SCHEMA_VERSION = '1.2.0';

	/** Maximum retry attempts for transient DB failures. */
	private const MAX_RETRIES = 3;

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
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
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
			schema_version varchar(20) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sequence (sequence),
			UNIQUE KEY previous_event_hash (previous_event_hash),
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

		// Ensure the singleton row exists.
		$wpdb->query( "INSERT IGNORE INTO {$seq_table} (id, current_value) VALUES (1, 0)" );
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
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! self::exists() ) {
			return false;
		}
		$table = self::table_name();

		// Enforce InnoDB (required for the FOR UPDATE row locking used by writes).
		$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		if ( '' !== $engine && 0 !== strcasecmp( $engine, 'InnoDB' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" );
		}

		$has_index = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s', $table, 'previous_event_hash' ) );
		if ( $has_index > 0 ) {
			return true;
		}
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY previous_event_hash (previous_event_hash)" );
		$has_index = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s', $table, 'previous_event_hash' ) );
		return $has_index > 0;
	}

	/**
	 * Record an event. Sensitive text is never accepted wholesale.
	 *
	 * @param bool $mandatory When true, failure throws an exception (fail-closed for state transitions).
	 * @return int Insert ID on success, 0 on non-mandatory failure.
	 * @throws \RuntimeException When $mandatory is true and the write fails after retries.
	 */
	public static function record( string $event_type, string $object_type, int $object_id, array $payload = array(), int $actor_id = 0, string $source_channel = 'system', bool $mandatory = false ): int {
		global $wpdb;
		$event_type     = substr( sanitize_key( $event_type ), 0, 64 );
		$object_type    = substr( sanitize_key( $object_type ), 0, 40 );
		$source_channel = substr( sanitize_key( $source_channel ), 0, 32 );
		$payload        = self::sanitize_payload( $payload );
		$request_id     = self::request_id();
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'insert' ) || ! self::exists() ) {
			self::record_failure( $event_type, 'table_unavailable' );
			if ( $mandatory ) {
				throw new \RuntimeException( 'Audit table unavailable and event is mandatory.' );
			}
			return 0;
		}

		$last_error = '';
		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; ++$attempt ) {
			$result = self::attempt_write( $wpdb, $event_type, $object_type, $object_id, $payload, $actor_id, $source_channel, $request_id );
			if ( $result > 0 ) {
				return $result;
			}
			$last_error = (string) ( $wpdb->last_error ?? 'unknown' );
			// Retry only on transient failures (duplicate key, deadlock, lock wait timeout).
			if ( ! self::is_transient_error( $last_error ) ) {
				break;
			}
			if ( $attempt < self::MAX_RETRIES ) {
				usleep( 50000 + wp_rand( 0, 150000 ) ); // 50-200ms jitter.
			}
		}

		self::record_failure( $event_type, $last_error );
		if ( $mandatory ) {
			throw new \RuntimeException( sprintf( 'Mandatory audit write failed after %d attempts: %s', self::MAX_RETRIES, $last_error ) );
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
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! self::exists() ) {
			return array( 'valid' => false, 'checked' => 0, 'errors' => array( 'audit_table_unavailable' ) );
		}
		$table     = self::table_name();
		$last_seq  = 0;
		$prev_hash = '';
		$prev_seq  = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE sequence > %d ORDER BY sequence ASC LIMIT %d", $last_seq, $batch_size ), ARRAY_A );
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
			'schema_version'      => (string) $row['schema_version'],
		);
		return hash( 'sha256', Approval_Fingerprint::canonical_json( $record ) );
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

	/** Get the current audit write failure count. */
	public static function failure_count(): int {
		return (int) get_option( 'lel_audit_write_failures', 0 );
	}

	/** Allocate the next sequence number atomically via the singleton row. */
	private static function allocate_sequence( $wpdb ): int {
		$seq_table = self::sequence_table_name();
		$wpdb->query( "UPDATE {$seq_table} SET current_value = LAST_INSERT_ID(current_value + 1) WHERE id = 1" );
		$seq = (int) $wpdb->insert_id;
		if ( $seq <= 0 ) {
			// Fallback: initialize the row if it does not exist yet.
			$wpdb->query( "INSERT IGNORE INTO {$seq_table} (id, current_value) VALUES (1, 1)" );
			$wpdb->query( "UPDATE {$seq_table} SET current_value = LAST_INSERT_ID(current_value + 1) WHERE id = 1" );
			$seq = (int) $wpdb->insert_id;
		}
		return $seq;
	}

	/** Attempt a single transactional write. Returns insert ID or 0. */
	private static function attempt_write( $wpdb, string $event_type, string $object_type, int $object_id, array $payload, int $actor_id, string $source_channel, string $request_id ): int {
		$table = self::table_name();
		$started = $wpdb->query( 'START TRANSACTION' );
		if ( false === $started ) {
			return 0;
		}
		$previous = (string) $wpdb->get_var( "SELECT event_hash FROM {$table} ORDER BY sequence DESC LIMIT 1 FOR UPDATE" );
		$next_seq = self::allocate_sequence( $wpdb );
		if ( $next_seq <= 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return 0;
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
			'schema_version'      => self::SCHEMA_VERSION,
		);
		$record['event_hash'] = hash( 'sha256', Approval_Fingerprint::canonical_json( $record ) );
		$inserted = $wpdb->insert( $table, $record );
		if ( false !== $inserted ) {
			$committed = $wpdb->query( 'COMMIT' );
			if ( false !== $committed ) {
				return (int) $wpdb->insert_id;
			}
			return 0;
		}
		$wpdb->query( 'ROLLBACK' );
		return 0;
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

	/** Record a write failure for observability. */
	private static function record_failure( string $event_type, string $reason ): void {
		$count = (int) get_option( 'lel_audit_write_failures', 0 );
		update_option( 'lel_audit_write_failures', $count + 1, false );
		Logger::error( 'audit_write_failure', array( 'event_type' => $event_type, 'reason' => substr( $reason, 0, 200 ) ) );
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
