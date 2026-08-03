<?php
/**
 * Asynchronous invalidation job queue.
 *
 * Replaces synchronous full-dataset invalidation scans with bounded,
 * deduplicated, retryable background processing via WP-Cron.
 *
 * Concurrency contract:
 * - Deduplication is enforced by the database via the uniq_open_parent
 *   unique key over (parent_post_id, open_marker); open_marker is 1 for
 *   open (pending/processing) jobs and NULL for terminal jobs, so NULLs
 *   never collide and history is preserved.
 * - Workers claim jobs with a single atomic UPDATE that sets a lease;
 *   there is no read-then-update window. Expired leases are reclaimable.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Bounded job queue for approval invalidation work. */
final class Invalidation_Queue {
	/** Cron hook for processing the queue. */
	public const PROCESS_HOOK = 'lel_invalidation_queue_process';

	/** Maximum jobs processed per cron run. */
	private const BATCH_SIZE = 50;

	/** Runtime cap in seconds per cron run. */
	private const RUNTIME_CAP = 30;

	/** Maximum retry attempts before marking a job as failed. */
	private const MAX_RETRIES = 5;

	/** Worker lease duration in seconds. */
	private const LEASE_SECONDS = 120;

	/** Backoff before a failed attempt becomes reclaimable, in seconds. */
	private const RETRY_BACKOFF_SECONDS = 30;

	/** Maximum retry delay, in seconds. */
	private const MAX_RETRY_BACKOFF_SECONDS = 3600;

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_invalidation_queue' : 'wp_lel_invalidation_queue';
	}

	/**
	 * Install the queue table (additive, idempotent).
	 *
	 * @throws \RuntimeException When the table or the audit idempotency constraint cannot be created.
	 */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_post_id bigint(20) unsigned NOT NULL,
			reason varchar(191) NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
			open_marker tinyint(1) unsigned DEFAULT NULL,
			lease_owner varchar(64) DEFAULT NULL,
			lease_expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at datetime DEFAULT NULL,
			last_error varchar(255) DEFAULT NULL,
			audit_event_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_open_parent (parent_post_id,open_marker),
			KEY status_created (status,created_at),
			KEY lease_expiry (status,lease_expires_at)
		) {$charset};";
		dbDelta( $sql );
		if ( ! self::exists() ) {
			throw new \RuntimeException( 'Invalidation queue table was not created.' );
		}
		if ( Audit_Log::exists() && ! Audit_Log::ensure_idempotency_constraint() ) {
			throw new \RuntimeException( 'Could not enforce idempotent invalidation audit delivery.' );
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

	/** Whether the fork-preventing unique open-job key is enforced. */
	public static function open_uniqueness_enforced(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'uniq_open_parent' AND NON_UNIQUE = 0",
				self::table_name()
			)
		);
		return (int) $count > 0;
	}

	/** Whether workers can safely use the installed schema, without changing it. */
	public static function schema_is_current(): bool {
		global $wpdb;
		if ( ! self::exists() || ! Audit_Log::exists() || ! self::open_uniqueness_enforced() ) {
			return false;
		}
		foreach ( array( 'id', 'parent_post_id', 'reason', 'actor_id', 'status', 'retry_count', 'open_marker', 'lease_owner', 'lease_expires_at', 'created_at', 'processed_at', 'last_error', 'audit_event_id' ) as $column ) {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', self::table_name(), $column ) );
			if ( 1 !== (int) $count ) {
				return false;
			}
		}
		$audit_unique = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0', Audit_Log::table_name(), 'idempotency_key' ) );
		return (int) $audit_unique > 0;
	}

	/**
	 * Enforce the uniq_open_parent unique key, collapsing duplicate open
	 * rows first (dbDelta does not reliably add UNIQUE keys in place).
	 */
	public static function ensure_open_uniqueness(): bool {
		global $wpdb;
		if ( ! self::exists() ) {
			return false;
		}
		$table = self::table_name();
		// Backfill the marker on legacy open rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; the statement has no external values.
		if ( false === $wpdb->query( "UPDATE {$table} SET open_marker = 1 WHERE status IN ('pending','processing') AND open_marker IS NULL" ) ) {
			return false;
		}
		// Clear the marker on legacy terminal rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; the statement has no external values.
		if ( false === $wpdb->query( "UPDATE {$table} SET open_marker = NULL WHERE status NOT IN ('pending','processing') AND open_marker IS NOT NULL" ) ) {
			return false;
		}
		if ( self::open_uniqueness_enforced() ) {
			return true;
		}
		// Collapse duplicate open rows: keep the lowest id per parent.
		if ( false === $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; the statement has no external values.
			"UPDATE {$table} q INNER JOIN (SELECT parent_post_id, MIN(id) AS keep_id FROM {$table} WHERE open_marker = 1 GROUP BY parent_post_id) k ON q.parent_post_id = k.parent_post_id SET q.open_marker = NULL, q.status = 'superseded', q.processed_at = UTC_TIMESTAMP() WHERE q.open_marker = 1 AND q.id <> k.keep_id"
		) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema DDL with identifiers from the trusted $wpdb->prefix; DDL cannot be parameterized.
		if ( false === $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_open_parent (parent_post_id, open_marker)" ) ) {
			return false;
		}
		return self::open_uniqueness_enforced();
	}

	/** Register hooks for queue processing. */
	public static function init(): void {
		add_action( self::PROCESS_HOOK, array( self::class, 'process_scheduled_batch' ) );
		add_action( 'init', array( self::class, 'schedule_processor' ), 35 );
	}

	/** Ensure the cron processor is scheduled. */
	public static function schedule_processor(): void {
		if ( ! Migrations::wordpress_ready() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::PROCESS_HOOK ) ) {
			wp_schedule_event( time() + 60, 'lel_every_minute', self::PROCESS_HOOK );
		}
	}

	/** Void cron callback around the count-returning worker API. */
	public static function process_scheduled_batch(): void {
		self::process_batch();
	}

	/**
	 * Enqueue invalidation jobs for a set of parent posts.
	 *
	 * Deduplication is a single INSERT ... ON DUPLICATE KEY UPDATE against
	 * the uniq_open_parent key — no racy SELECT-then-INSERT probe.
	 *
	 * @param integer[] $parent_ids Post IDs to invalidate.
	 * @param string    $reason    Invalidation reason.
	 * @param int       $actor_id  Actor triggering the invalidation.
	 * @throws \RuntimeException When a job can be neither queued nor applied directly.
	 */
	public static function enqueue( array $parent_ids, string $reason, int $actor_id = 0 ): void {
		if ( empty( $parent_ids ) ) {
			return;
		}
		if ( ! self::schema_is_current() ) {
			self::invalidate_synchronously( $parent_ids, $reason . ':queue_unavailable', $actor_id );
			return;
		}
		$reason = substr( sanitize_text_field( $reason ), 0, 191 );
		foreach ( array_unique( $parent_ids ) as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}
			if ( false === self::insert_open_row( $pid, $reason, $actor_id ) ) {
				self::record_enqueue_failure( $pid, self::database_error( $GLOBALS['wpdb'] ) );
				try {
					Approval_Service::invalidate_direct( $pid, $reason . ':enqueue_fallback', $actor_id );
				} catch ( \Throwable $error ) {
					self::record_fallback_failure( $pid, $error->getMessage() );
					throw new \RuntimeException( esc_html( sprintf( 'Invalidation could not be queued or applied for post %d.', $pid ) ), 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $error is the caught \Throwable passed as the previous exception for chaining, not output.
				}
			}
		}
		self::schedule_processing( 5 );
	}

	/**
	 * Persist retry work after a direct invalidation outcome could not be audited.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $reason    Invalidation reason.
	 * @param int    $actor_id  Actor triggering the invalidation.
	 * @throws \RuntimeException When the reconciliation job cannot be queued.
	 */
	public static function enqueue_reconciliation( int $parent_id, string $reason, int $actor_id = 0 ): void {
		if ( $parent_id <= 0 ) {
			return;
		}
		if ( ! self::schema_is_current() ) {
			self::record_enqueue_failure( $parent_id, 'Invalidation reconciliation queue schema unavailable.' );
			throw new \RuntimeException( 'Invalidation reconciliation queue is unavailable.' );
		}
		$reason = substr( sanitize_text_field( $reason ), 0, 191 );
		if ( false === self::insert_open_row( $parent_id, $reason, $actor_id ) ) {
			self::record_enqueue_failure( $parent_id, self::database_error( $GLOBALS['wpdb'] ) );
			throw new \RuntimeException( esc_html( sprintf( 'Invalidation reconciliation could not be queued for post %d.', $parent_id ) ) );
		}
		self::schedule_processing( 5 );
	}

	/**
	 * Enqueue a batch of invalidation jobs atomically (all rows or none).
	 *
	 * WordPress meta/post writes autocommit outside our control, so full
	 * cross-system atomicity is impossible; instead the queue row batch is
	 * its own transaction and any failure is surfaced to the caller rather
	 * than silently dropped — the queue doubles as the durable outbox.
	 * Cron scheduling is deferred until after COMMIT.
	 *
	 * @param integer[] $parent_ids Post IDs to invalidate.
	 * @param string    $reason    Invalidation reason.
	 * @param int       $actor_id  Actor triggering the invalidation.
	 * @throws \RuntimeException When the table is unavailable or any insert fails (after ROLLBACK).
	 */
	public static function enqueue_in_transaction( array $parent_ids, string $reason, int $actor_id = 0 ): void {
		global $wpdb;
		$parent_ids = array_values( array_filter( array_map( 'intval', array_unique( $parent_ids ) ), static fn( int $pid ): bool => $pid > 0 ) );
		if ( empty( $parent_ids ) ) {
			return;
		}
		if ( ! self::schema_is_current() ) {
			throw new \RuntimeException( 'Invalidation queue schema unavailable for transactional enqueue.' );
		}
		$reason = substr( sanitize_text_field( $reason ), 0, 191 );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( esc_html( 'Could not open transaction for invalidation enqueue: ' . self::database_error( $wpdb ) ) );
		}
		$committed = false;
		try {
			foreach ( $parent_ids as $pid ) {
				if ( false === self::insert_open_row( $pid, $reason, $actor_id ) ) {
					throw new \RuntimeException( sprintf( 'Invalidation enqueue failed for post %d: %s', $pid, (string) ( $wpdb->last_error ?? 'unknown' ) ) );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Invalidation enqueue COMMIT failed: ' . (string) ( $wpdb->last_error ?? 'unknown' ) );
			}
			$committed = true;
		} finally {
			if ( ! $committed && false === $wpdb->query( 'ROLLBACK' ) ) {
				self::record_enqueue_failure( 0, 'Invalidation enqueue rollback failed: ' . self::database_error( $wpdb ) );
			}
		}
		self::schedule_processing( 5 );
	}

	/**
	 * Insert one open queue row; the unique key dedups concurrent enqueues.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $reason    Invalidation reason (already sanitized/bounded).
	 * @param int    $actor_id  Actor triggering the invalidation.
	 * @return int|false Rows affected, or false on database error.
	 */
	private static function insert_open_row( int $parent_id, string $reason, int $actor_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; values use prepared placeholders.
				"INSERT INTO {$table} (parent_post_id, reason, actor_id, status, open_marker, created_at) VALUES (%d, %s, %d, 'pending', 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id = id",
				$parent_id,
				$reason,
				$actor_id
			)
		);
	}

	/**
	 * Schedule near-term queue processing if not already scheduled.
	 *
	 * @param int $delay Seconds to wait before processing.
	 */
	public static function schedule_processing( int $delay = 5 ): void {
		if ( ! wp_next_scheduled( self::PROCESS_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::PROCESS_HOOK );
		}
	}

	/**
	 * Record a synchronous fallback invalidation failure for observability.
	 *
	 * @param int    $post_id Affected post ID.
	 * @param string $reason  Failure reason.
	 */
	private static function record_fallback_failure( int $post_id, string $reason ): void {
		$count = (int) get_option( 'lel_invalidation_fallback_failures', 0 );
		self::write_counter( 'lel_invalidation_fallback_failures', $count + 1 );
		Logger::warning(
			'invalidation_fallback_failure',
			array(
				'post_id' => $post_id,
				'reason'  => substr( $reason, 0, 200 ),
			)
		);
	}

	/**
	 * Apply invalidation immediately when its durable queue cannot be trusted.
	 *
	 * @param integer[] $parent_ids Post IDs to invalidate.
	 * @param string    $reason     Invalidation reason.
	 * @param int       $actor_id   Actor triggering the invalidation.
	 * @throws \RuntimeException When synchronous invalidation fails for a post.
	 */
	private static function invalidate_synchronously( array $parent_ids, string $reason, int $actor_id ): void {
		foreach ( array_unique( array_map( 'intval', $parent_ids ) ) as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}
			try {
				Approval_Service::invalidate_direct( $post_id, $reason, $actor_id );
			} catch ( \Throwable $error ) {
				self::record_fallback_failure( $post_id, $error->getMessage() );
				throw new \RuntimeException( esc_html( sprintf( 'Invalidation queue unavailable and synchronous invalidation failed for post %d.', $post_id ) ), 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $error is the caught \Throwable passed as the previous exception for chaining, not output.
			}
		}
	}

	/**
	 * Record a durable-queue write failure so readiness cannot report healthy.
	 *
	 * @param int    $post_id Affected post ID.
	 * @param string $reason  Failure reason.
	 */
	private static function record_enqueue_failure( int $post_id, string $reason ): void {
		$count = (int) get_option( 'lel_invalidation_enqueue_failures', 0 );
		self::write_counter( 'lel_invalidation_enqueue_failures', $count + 1 );
		Logger::error(
			'invalidation_enqueue_failed',
			array(
				'post_id' => $post_id,
				'reason'  => substr( $reason, 0, 200 ),
			)
		);
	}

	/** Current synchronous fallback failure count. */
	public static function fallback_failure_count(): int {
		return (int) get_option( 'lel_invalidation_fallback_failures', 0 );
	}

	/** Queue insert failures observed since the last operator reconciliation. */
	public static function enqueue_failure_count(): int {
		return (int) get_option( 'lel_invalidation_enqueue_failures', 0 );
	}

	/**
	 * Atomically claim the next available job for this worker.
	 *
	 * A job is available when pending, or processing with an expired lease
	 * (crashed worker). The UPDATE is the claim — affected rows decide.
	 *
	 * @param string $worker Worker identity for the lease.
	 * @return array<string, mixed>|null Claimed job row, or null when none.
	 * @throws \RuntimeException When the lease claim or the claimed-row load fails.
	 */
	private static function claim_next( string $worker ): ?array {
		global $wpdb;
		$table   = self::table_name();
		$claimed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; values use prepared placeholders.
				"UPDATE {$table} SET status = 'processing', lease_owner = %s, lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND) WHERE open_marker = 1 AND (status = 'pending' OR (status = 'processing' AND lease_expires_at < UTC_TIMESTAMP())) ORDER BY id ASC LIMIT 1",
				$worker,
				self::LEASE_SECONDS
			)
		);
		if ( false === $claimed ) {
			throw new \RuntimeException( esc_html( 'Invalidation lease claim failed: ' . self::database_error( $wpdb ) ) );
		}
		if ( 0 === (int) $claimed ) {
			return null;
		}
		if ( 1 !== (int) $claimed ) {
			throw new \RuntimeException( 'Invalidation lease claim affected an unexpected number of rows.' );
		}
		self::clear_database_error( $wpdb );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; the value uses a prepared placeholder.
				"SELECT * FROM {$table} WHERE lease_owner = %s AND status = 'processing' LIMIT 1",
				$worker
			),
			ARRAY_A
		);
		if ( self::database_failed( $wpdb ) ) {
			throw new \RuntimeException( esc_html( 'Claimed invalidation row could not be loaded: ' . self::database_error( $wpdb ) ) );
		}
		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( 'Invalidation lease was acquired but its row was not found.' );
		}
		return $row;
	}

	/**
	 * Process pending invalidation jobs in bounded batches.
	 *
	 * @return int Number of jobs processed.
	 * @throws \RuntimeException When the schema is unavailable or a job transition fails.
	 */
	public static function process_batch(): int {
		global $wpdb;
		if ( ! self::schema_is_current() ) {
			throw new \RuntimeException( 'Invalidation queue integrity schema is unavailable.' );
		}
		$table     = self::table_name();
		$start     = time();
		$processed = 0;
		$worker    = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( microtime( true ) . wp_rand() );

		while ( $processed < self::BATCH_SIZE && ( time() - $start ) < self::RUNTIME_CAP ) {
			if ( ! Audit_Log::request_is_healthy() ) {
				break;
			}
			$job = self::claim_next( $worker );
			if ( null === $job ) {
				break;
			}

			$job_id  = (int) $job['id'];
			$post_id = (int) $job['parent_post_id'];
			$reason  = (string) $job['reason'];
			$actor   = (int) $job['actor_id'];

			try {
				$audit_id     = Approval_Service::invalidate_direct( $post_id, $reason, $actor, true, 'invalidation_queue:' . $job_id );
				$transitioned = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; values use prepared placeholders.
						"UPDATE {$table} SET status = 'completed', open_marker = NULL, lease_owner = NULL, lease_expires_at = NULL, audit_event_id = %d, processed_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'processing' AND lease_owner = %s",
						$audit_id,
						$job_id,
						$worker
					)
				);
				if ( 1 !== (int) $transitioned ) {
					throw new \RuntimeException( 'Invalidation completion lease was lost or the completion write failed.' );
				}
			} catch ( \Throwable $e ) {
				if ( '' !== Audit_Log::unhealthy_reason() ) {
					Logger::error(
						'invalidation_request_quarantined',
						array(
							'job_id'  => $job_id,
							'post_id' => $post_id,
							'error'   => substr( $e->getMessage(), 0, 200 ),
						)
					);
					++$processed;
					break;
				}
				$retries = (int) $job['retry_count'] + 1;
				$error   = substr( $e->getMessage(), 0, 255 );
				if ( $retries >= self::MAX_RETRIES ) {
					$transitioned = $wpdb->query(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from the trusted $wpdb->prefix; values use prepared placeholders.
							"UPDATE {$table} SET status = 'failed', retry_count = %d, last_error = %s, open_marker = NULL, lease_owner = NULL, lease_expires_at = NULL, processed_at = UTC_TIMESTAMP() WHERE id = %d AND lease_owner = %s",
							$retries,
							$error,
							$job_id,
							$worker
						)
					);
					Logger::error(
						'invalidation_job_failed',
						array(
							'job_id'  => $job_id,
							'post_id' => $post_id,
							'error'   => substr( (string) $error, 0, 200 ),
						)
					);
				} else {
					// Keep the open slot; the lease acts as retry backoff.
					$backoff      = min( self::MAX_RETRY_BACKOFF_SECONDS, self::RETRY_BACKOFF_SECONDS * ( 2 ** ( $retries - 1 ) ) );
					$transitioned = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} SET retry_count = %d, last_error = %s, lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND) WHERE id = %d AND lease_owner = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted $wpdb->prefix table; values bound via placeholders.
							$retries,
							$error,
							$backoff,
							$job_id,
							$worker
						)
					);
				}
				if ( 1 !== (int) $transitioned ) {
					self::record_enqueue_failure( $post_id, 'Could not persist invalidation retry/dead-letter transition.' );
					throw new \RuntimeException( 'Invalidation retry/dead-letter lease transition failed.' );
				}
			}
			++$processed;
		}

		// Reschedule if more work remains.
		self::clear_database_error( $wpdb );
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE open_marker = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted $wpdb->prefix table; no user-supplied values.
		if ( self::database_failed( $wpdb ) ) {
			throw new \RuntimeException( esc_html( 'Could not inspect remaining invalidation work: ' . self::database_error( $wpdb ) ) );
		}
		if ( $remaining > 0 ) {
			self::schedule_processing( self::RETRY_BACKOFF_SECONDS );
		}

		return $processed;
	}

	/**
	 * Get queue statistics for observability.
	 *
	 * @return array{pending: int, processing: int, completed: int, failed: int, oldest_pending_age_seconds: int}
	 */
	public static function stats(): array {
		global $wpdb;
		if ( ! self::exists() ) {
			return array(
				'pending'                    => 0,
				'processing'                 => 0,
				'completed'                  => 0,
				'failed'                     => 0,
				'oldest_pending_age_seconds' => 0,
				'table_missing'              => true,
			);
		}
		$table      = self::table_name();
		$pending    = self::scalar( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
		$processing = self::scalar( "SELECT COUNT(*) FROM {$table} WHERE status = 'processing'" );
		$completed  = self::scalar( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
		$failed     = self::scalar( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
		$oldest     = self::scalar( "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1" );
		return array(
			'pending'                    => $pending,
			'processing'                 => $processing,
			'completed'                  => $completed,
			'failed'                     => $failed,
			'oldest_pending_age_seconds' => $oldest ? (int) $oldest : 0,
		);
	}

	/**
	 * Purge completed jobs older than a given number of days (housekeeping).
	 *
	 * @param int $older_than_days Age threshold in days.
	 * @throws \RuntimeException When the purge query fails.
	 */
	public static function purge_completed( int $older_than_days = 7 ): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		$table   = self::table_name();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'completed' AND audit_event_id IS NOT NULL AND processed_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted $wpdb->prefix table; cutoff bound via placeholder.
				$cutoff
			)
		);
		if ( false === $deleted ) {
			throw new \RuntimeException( esc_html( 'Could not purge audit-backed invalidation jobs: ' . self::database_error( $wpdb ) ) );
		}
		return (int) $deleted;
	}

	/**
	 * Read a scalar and surface database errors instead of casting them to zero.
	 *
	 * @param string $sql SQL statement to execute.
	 * @throws \RuntimeException When the read fails.
	 */
	private static function scalar( string $sql ): int {
		global $wpdb;
		self::clear_database_error( $wpdb );
		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass literal COUNT/TIMESTAMPDIFF SQL over the trusted $wpdb->prefix table; no user input.
		if ( self::database_failed( $wpdb ) ) {
			throw new \RuntimeException( esc_html( 'Invalidation queue read failed: ' . self::database_error( $wpdb ) ) );
		}
		return (int) $value;
	}

	/**
	 * Persist a monotonic failure counter and loudly report storage failure.
	 *
	 * @param string $name  Option name.
	 * @param int    $value Counter value to persist.
	 */
	private static function write_counter( string $name, int $value ): void {
		if ( ! update_option( $name, $value, false ) && (int) get_option( $name, 0 ) !== $value ) {
			error_log( 'Longevity invalidation failure counter could not be persisted: ' . $name ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Current bounded database error.
	 *
	 * @param \wpdb|object $wpdb WordPress database abstraction.
	 */
	private static function database_error( $wpdb ): string {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		return '' === $error ? 'unknown_database_error' : substr( $error, 0, 200 );
	}

	/**
	 * Clear stale wpdb error state before a read.
	 *
	 * @param \wpdb $wpdb WordPress database abstraction.
	 */
	private static function clear_database_error( $wpdb ): void {
		$wpdb->last_error = '';
	}

	/**
	 * Whether the latest wpdb operation reported an error.
	 *
	 * @param \wpdb|object $wpdb WordPress database abstraction.
	 */
	private static function database_failed( $wpdb ): bool {
		return '' !== trim( (string) ( $wpdb->last_error ?? '' ) );
	}
}
