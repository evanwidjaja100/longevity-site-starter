<?php
/**
 * Durable notification outbox.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The outbox is an authoritative custom table; names come only from the trusted WordPress prefix.
/** Idempotent application enqueue with lease-based, at-least-once mail delivery. */
final class Notification_Outbox {
	public const SEND_HOOK          = 'lel_notification_outbox_send';
	public const MAX_ATTEMPTS       = 5;
	public const STATUS_PENDING     = 'pending';
	public const STATUS_LEASED      = 'leased';
	public const STATUS_DELIVERED   = 'delivered';
	public const STATUS_RETRY_WAIT  = 'retry_wait';
	public const STATUS_DEAD_LETTER = 'dead_letter';
	public const STATUS_CANCELLED   = 'cancelled';

	private const BATCH_SIZE         = 25;
	private const RUNTIME_CAP        = 20;
	private const LEASE_SECONDS      = 120;
	private const BASE_RETRY_SECONDS = 60;
	private const MAX_RETRY_SECONDS  = 3600;

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_notification_outbox' : 'wp_lel_notification_outbox';
	}

	/** Install the additive outbox schema. */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			notification_type varchar(64) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dedupe_key char(64) NOT NULL,
			recipient varchar(255) NOT NULL,
			payload_json longtext NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			last_error varchar(64) DEFAULT NULL,
			lease_owner varchar(64) DEFAULT NULL,
			lease_expires_at datetime DEFAULT NULL,
			next_attempt_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY state_due (status,next_attempt_at,created_at),
			KEY object_type (object_id,notification_type)
		) {$charset};";
		dbDelta( $sql );
	}

	/** Whether the table exists. */
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

	/** Register the delivery worker. */
	public static function init(): void {
		add_action(
			self::SEND_HOOK,
			static function (): void {
				self::process_batch();
			}
		);
		add_action( 'init', array( self::class, 'schedule_sender' ), 35 );
	}

	/** Ensure the recurring worker is scheduled. */
	public static function schedule_sender(): void {
		if ( ! Migrations::wordpress_ready() || wp_next_scheduled( self::SEND_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + self::LEASE_SECONDS, 'hourly', self::SEND_HOOK );
	}

	/** Whether a mail transport has explicitly been configured. */
	public static function transport_available(): bool {
		return defined( 'SMTP_HOST' ) || ( function_exists( 'has_action' ) && has_action( 'phpmailer_init' ) );
	}

	/**
	 * Stable application-enqueue key for database deduplication and correlation.
	 *
	 * @param string $type            Notification type.
	 * @param int    $object_id       Related object ID.
	 * @param string $idempotency_key Optional request key.
	 */
	public static function dedupe_key( string $type, int $object_id, string $idempotency_key = '' ): string {
		$source = '' !== $idempotency_key ? $idempotency_key : (string) $object_id;
		return hash( 'sha256', sanitize_key( $type ) . '|' . $source );
	}

	/**
	 * Enqueue one durable notification. Repeated calls with the same key return
	 * the existing row instead of creating another delivery.
	 *
	 * @param string $type            Notification type.
	 * @param int    $object_id       Authoritative contact record ID.
	 * @param string $recipient       Recipient address.
	 * @param array  $payload         Subject, body, and headers.
	 * @param string $idempotency_key Optional aggregate idempotency key.
	 * @return int|null Row ID, or null when durability could not be proved.
	 */
	public static function enqueue( string $type, int $object_id, string $recipient, array $payload, string $idempotency_key = '' ): ?int {
		global $wpdb;
		$type      = substr( sanitize_key( $type ), 0, 64 );
		$recipient = sanitize_email( $recipient );
		if ( ! self::exists() || '' === $type || $object_id < 1 || ! is_email( $recipient ) ) {
			return null;
		}
		$payload = self::normalized_payload( $payload );
		if ( null === $payload ) {
			return null;
		}

		$key      = self::dedupe_key( $type, $object_id, $idempotency_key );
		$existing = self::find_by_key( $key );
		if ( false === $existing ) {
			return null;
		}
		if ( null !== $existing ) {
			return (int) $existing['object_id'] === $object_id ? (int) $existing['id'] : null;
		}

		self::clear_db_error();
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'notification_type' => $type,
				'object_id'         => $object_id,
				'dedupe_key'        => $key,
				'recipient'         => substr( $recipient, 0, 255 ),
				'payload_json'      => wp_json_encode( $payload ),
				'status'            => self::STATUS_PENDING,
				'attempts'          => 0,
				'next_attempt_at'   => gmdate( 'Y-m-d H:i:s' ),
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( false === $inserted ) {
			// A concurrent insert may have won the unique-key race.
			$existing = self::find_by_key( $key );
			if ( is_array( $existing ) && (int) $existing['object_id'] === $object_id ) {
				Logger::warning(
					'notification_outbox_insert_race',
					array(
						'object_id' => $object_id,
						'type'      => $type,
					)
				);
				return (int) $existing['id'];
			}
			self::report_sql_failure(
				'enqueue',
				array(
					'object_id' => $object_id,
					'type'      => $type,
				)
			);
			return null;
		}
		if ( 1 !== (int) $inserted || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'enqueue_row_count', array( 'row_count' => (int) $inserted ) );
			return null;
		}
		$row_id = (int) $wpdb->insert_id;
		if ( $row_id < 1 ) {
			self::report_sql_failure(
				'enqueue_missing_insert_id',
				array(
					'object_id' => $object_id,
					'type'      => $type,
				)
			);
			return null;
		}
		if ( '' === (string) get_option( 'lel_e2e_contact_fixture_token', '' ) && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::SEND_HOOK ) ) {
			wp_schedule_single_event( time() + self::BASE_RETRY_SECONDS, self::SEND_HOOK );
		}
		return $row_id;
	}

	/**
	 * Remove all PII-bearing notifications for an expired contact record.
	 *
	 * @param int $object_id Contact record ID.
	 */
	public static function purge_for_object( int $object_id ): bool {
		global $wpdb;
		if ( $object_id < 1 ) {
			return false;
		}
		self::clear_db_error();
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'purge_table_check', array( 'object_id' => $object_id ) );
			return false;
		}
		if ( $table !== $found ) {
			return true;
		}
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table . ' WHERE object_id = %d AND notification_type = %s',
				$object_id,
				'contact_admin_notification'
			)
		);
		// @phpstan-ignore notIdentical.alwaysFalse (wpdb::query() mutates last_error; PHPStan cannot see writes to the magic property)
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'purge_for_object', array( 'object_id' => $object_id ) );
			return false;
		}
		return true;
	}

	/**
	 * Cancel undelivered work and redact all payload PII for an object.
	 *
	 * @param int $object_id Contact record ID.
	 */
	public static function cancel_for_object( int $object_id ): bool {
		global $wpdb;
		if ( $object_id < 1 || ! self::exists() ) {
			return false;
		}
		$table = self::table_name();
		self::clear_db_error();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = IF(status IN ('pending','leased','retry_wait'), 'cancelled', status), recipient = '', payload_json = '{}', lease_owner = NULL, lease_expires_at = NULL, next_attempt_at = NULL WHERE object_id = %d AND notification_type = %s",
				$object_id,
				'contact_admin_notification'
			)
		);
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'cancel_for_object', array( 'object_id' => $object_id ) );
			return false;
		}
		return true;
	}

	/**
	 * Atomically lease one due row.
	 *
	 * @param string $worker Unique worker identity.
	 */
	private static function claim_next( string $worker ): ?array {
		global $wpdb;
		$table = self::table_name();
		self::clear_db_error();
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lease_owner = %s, lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), status = 'leased' WHERE ((status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())) OR (status = 'retry_wait' AND next_attempt_at <= UTC_TIMESTAMP()) OR (status = 'leased' AND lease_expires_at < UTC_TIMESTAMP())) ORDER BY id ASC LIMIT 1",
				$worker,
				self::LEASE_SECONDS
			)
		);
		if ( false === $claimed || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'claim_next' );
			return null;
		}
		if ( 0 === (int) $claimed ) {
			return null;
		}
		if ( 1 !== (int) $claimed ) {
			self::report_sql_failure( 'claim_next_row_count', array( 'row_count' => (int) $claimed ) );
			return null;
		}
		self::clear_db_error();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE lease_owner = %s AND status = 'leased' LIMIT 1", $worker ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			self::report_sql_failure( 'claim_next_readback' );
			return null;
		}
		return $row;
	}

	/** Deliver a bounded batch. No configured transport leaves rows untouched. */
	public static function process_batch(): int {
		if ( '' !== (string) get_option( 'lel_e2e_contact_fixture_token', '' ) || ! self::exists() || ! self::transport_available() ) {
			return 0;
		}
		$start  = time();
		$sent   = 0;
		$worked = 0;
		$worker = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', (string) microtime( true ) );
		$seen   = array();

		while ( $worked < self::BATCH_SIZE && ( time() - $start ) < self::RUNTIME_CAP ) {
			$row = self::claim_next( $worker );
			if ( null === $row ) {
				break;
			}
			if ( isset( $seen[ (int) $row['id'] ] ) ) {
				// One provider attempt per notification per run. Retry scheduling,
				// never a tight worker loop, controls subsequent attempts.
				break;
			}
			$seen[ (int) $row['id'] ] = true;
			++$worked;
			$row_id  = (int) $row['id'];
			$payload = json_decode( (string) $row['payload_json'], true );
			$payload = is_array( $payload ) ? self::normalized_payload( $payload ) : null;
			if ( null === $payload ) {
				self::finish_failure( $row, $worker, 'invalid_payload', true );
				continue;
			}

			$headers        = $payload['headers'];
			$headers[]      = 'X-LEL-Enqueue-Key: ' . (string) $row['dedupe_key'];
			$error_category = 'transport_rejected';
			try {
				// wp_mail may hand off successfully before the lease update commits;
				// retries therefore provide at-least-once, not idempotent, delivery.
				$delivered = wp_mail( (string) $row['recipient'], $payload['subject'], $payload['body'], $headers );
			} catch ( \Throwable $error ) {
				unset( $error );
				$delivered      = false;
				$error_category = 'transport_exception';
			}
			if ( $delivered ) {
				if ( self::finish_success( $row_id, $worker ) ) {
					++$sent;
				}
				continue;
			}
			self::finish_failure( $row, $worker, $error_category );
		}
		return $sent;
	}

	/**
	 * Complete delivery only while this worker still owns exactly one leased row.
	 *
	 * @param int    $row_id Outbox row ID.
	 * @param string $worker Lease owner.
	 */
	private static function finish_success( int $row_id, string $worker ): bool {
		global $wpdb;
		$table = self::table_name();
		self::clear_db_error();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'delivered', lease_owner = NULL, lease_expires_at = NULL, next_attempt_at = NULL, last_error = NULL, sent_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'leased' AND lease_owner = %s",
				$row_id,
				$worker
			)
		);
		if ( 1 !== (int) $updated || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure(
				'complete_lease',
				array(
					'outbox_id' => $row_id,
					'row_count' => false === $updated ? false : (int) $updated,
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Record retry_wait or the terminal dead_letter state.
	 *
	 * @param array  $row       Claimed outbox row.
	 * @param string $worker    Worker holding the lease.
	 * @param string $category  Redacted failure category.
	 * @param bool   $permanent Whether retry cannot succeed.
	 */
	private static function finish_failure( array $row, string $worker, string $category, bool $permanent = false ): bool {
		global $wpdb;
		$table    = self::table_name();
		$row_id   = (int) $row['id'];
		$attempts = (int) $row['attempts'] + 1;
		if ( $permanent || $attempts >= self::MAX_ATTEMPTS ) {
			self::clear_db_error();
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'dead_letter', attempts = %d, last_error = %s, lease_owner = NULL, lease_expires_at = NULL, next_attempt_at = NULL WHERE id = %d AND status = 'leased' AND lease_owner = %s",
					$attempts,
					$category,
					$row_id,
					$worker
				)
			);
			if ( 1 !== (int) $updated || '' !== (string) $wpdb->last_error ) {
				self::report_sql_failure(
					'dead_letter_lease',
					array(
						'outbox_id' => $row_id,
						'row_count' => false === $updated ? false : (int) $updated,
					)
				);
				return false;
			}
			Logger::error(
				'notification_delivery_dead_letter',
				array(
					'outbox_id' => $row_id,
					'type'      => (string) $row['notification_type'],
					'attempts'  => $attempts,
					'category'  => $category,
				)
			);
			return true;
		}
		$delay = min( self::MAX_RETRY_SECONDS, self::BASE_RETRY_SECONDS * ( 2 ** ( $attempts - 1 ) ) );
		self::clear_db_error();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'retry_wait', attempts = %d, last_error = %s, lease_owner = NULL, lease_expires_at = NULL, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND) WHERE id = %d AND status = 'leased' AND lease_owner = %s",
				$attempts,
				$category,
				$delay,
				$row_id,
				$worker
			)
		);
		if ( 1 !== (int) $updated || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure(
				'retry_lease',
				array(
					'outbox_id' => $row_id,
					'row_count' => false === $updated ? false : (int) $updated,
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate and normalize a delivery payload.
	 *
	 * @param array $payload Candidate payload.
	 * @return array{subject: string, body: string, headers: array<int, string>}|null
	 */
	private static function normalized_payload( array $payload ): ?array {
		$subject = trim( (string) ( $payload['subject'] ?? '' ) );
		$body    = (string) ( $payload['body'] ?? '' );
		$headers = array_map( 'strval', (array) ( $payload['headers'] ?? array() ) );
		if ( '' === $subject || '' === $body || preg_match( '/[\r\n]/', $subject ) ) {
			return null;
		}
		foreach ( $headers as $header ) {
			if ( preg_match( '/[\r\n]/', $header ) ) {
				return null;
			}
		}
		return compact( 'subject', 'body', 'headers' );
	}

	/**
	 * Find an existing row by durable dedupe key.
	 *
	 * @param string $key Dedupe key.
	 * @return array<string, mixed>|false|null False means the lookup failed.
	 */
	private static function find_by_key( string $key ): array|false|null {
		global $wpdb;
		self::clear_db_error();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, object_id FROM ' . self::table_name() . ' WHERE dedupe_key = %s LIMIT 1', $key ),
			ARRAY_A
		);
		if ( false === $row || ( null === $row && '' !== (string) $wpdb->last_error ) ) {
			self::report_sql_failure( 'find_by_key' );
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/** Explicit-state counts, or an unavailable result when any count cannot be read. */
	public static function stats(): array {
		global $wpdb;
		if ( ! self::exists() ) {
			return self::unavailable_stats( '' !== (string) $wpdb->last_error ? 'database_error' : 'table_missing', '' === (string) $wpdb->last_error );
		}
		$counts = array();
		foreach ( array( self::STATUS_PENDING, self::STATUS_LEASED, self::STATUS_RETRY_WAIT, self::STATUS_DELIVERED, self::STATUS_DEAD_LETTER, 'failed', self::STATUS_CANCELLED ) as $status ) {
			$count = self::count_status( $status );
			if ( null === $count ) {
				return self::unavailable_stats( 'query_failed' );
			}
			$counts[ $status ] = $count;
		}
		return array(
			'available'     => true,
			'error'         => '',
			'table_missing' => false,
			'pending'       => $counts[ self::STATUS_PENDING ] + $counts[ self::STATUS_LEASED ] + $counts[ self::STATUS_RETRY_WAIT ],
			'sent'          => $counts[ self::STATUS_DELIVERED ],
			'failed'        => $counts[ self::STATUS_DEAD_LETTER ] + $counts['failed'],
			'leased'        => $counts[ self::STATUS_LEASED ],
			'retry_wait'    => $counts[ self::STATUS_RETRY_WAIT ],
			'delivered'     => $counts[ self::STATUS_DELIVERED ],
			'dead_letter'   => $counts[ self::STATUS_DEAD_LETTER ],
			'cancelled'     => $counts[ self::STATUS_CANCELLED ],
		);
	}

	/**
	 * Count one explicit state, returning null rather than a false zero.
	 *
	 * @param string $status Outbox status.
	 */
	private static function count_status( string $status ): ?int {
		global $wpdb;
		self::clear_db_error();
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE status = %s', $status ) );
		if ( false === $value || null === $value || '' !== (string) $wpdb->last_error ) {
			self::report_sql_failure( 'stats', array( 'status' => $status ) );
			return null;
		}
		return (int) $value;
	}

	/**
	 * Stats shape used when the table or a query is unavailable.
	 *
	 * @param string $error         Stable failure category.
	 * @param bool   $table_missing Whether the outbox table is absent.
	 */
	private static function unavailable_stats( string $error, bool $table_missing = false ): array {
		return array(
			'available'     => false,
			'error'         => $error,
			'pending'       => null,
			'sent'          => null,
			'failed'        => null,
			'leased'        => null,
			'retry_wait'    => null,
			'delivered'     => null,
			'dead_letter'   => null,
			'cancelled'     => null,
			'table_missing' => $table_missing,
		);
	}

	/** Reset wpdb's operation-local error before authoritative SQL. */
	private static function clear_db_error(): void {
		global $wpdb;
		$wpdb->last_error = '';
	}

	/**
	 * Emit a bounded operator-visible failure for an outbox SQL operation.
	 *
	 * @param string $operation SQL operation name.
	 * @param array  $context   Bounded diagnostic context.
	 */
	private static function report_sql_failure( string $operation, array $context = array() ): void {
		global $wpdb;
		Logger::error(
			'notification_outbox_sql_failed',
			array_merge(
				array(
					'operation' => $operation,
					'db_error'  => substr( (string) $wpdb->last_error, 0, 160 ),
				),
				$context
			)
		);
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
