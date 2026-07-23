<?php
/**
 * Asynchronous invalidation job queue.
 *
 * Replaces synchronous full-dataset invalidation scans with bounded,
 * deduplicated, retryable background processing via WP-Cron.
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
	private const MAX_RETRIES = 3;

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_invalidation_queue' : 'wp_lel_invalidation_queue';
	}

	/** Install the queue table (additive, idempotent). */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
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
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at datetime DEFAULT NULL,
			last_error varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status,created_at),
			KEY dedup (parent_post_id,status)
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

	/** Register hooks for queue processing. */
	public static function init(): void {
		add_action( self::PROCESS_HOOK, array( self::class, 'process_batch' ) );
		add_action( 'init', array( self::class, 'schedule_processor' ), 35 );
	}

	/** Ensure the cron processor is scheduled. */
	public static function schedule_processor(): void {
		if ( ! wp_next_scheduled( self::PROCESS_HOOK ) ) {
			wp_schedule_event( time() + 60, 'lel_every_minute', self::PROCESS_HOOK );
		}
	}

	/**
	 * Enqueue invalidation jobs for a set of parent posts (deduplicated).
	 *
	 * @param list<int> $parent_ids Post IDs to invalidate.
	 * @param string    $reason    Invalidation reason.
	 * @param int       $actor_id  Actor triggering the invalidation.
	 */
	public static function enqueue( array $parent_ids, string $reason, int $actor_id = 0 ): void {
		global $wpdb;
		if ( empty( $parent_ids ) || ! self::exists() ) {
			// Fallback: if table unavailable, execute synchronously for safety.
			foreach ( array_unique( $parent_ids ) as $pid ) {
				Approval_Service::invalidate_direct( (int) $pid, $reason, $actor_id );
			}
			return;
		}
		$table  = self::table_name();
		$reason = substr( sanitize_text_field( $reason ), 0, 191 );
		foreach ( array_unique( $parent_ids ) as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}
			// Deduplicate: skip if a pending job already exists for this post.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE parent_post_id = %d AND status = 'pending' LIMIT 1",
					$pid
				)
			);
			if ( $existing ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (parent_post_id, reason, actor_id, status, created_at) VALUES (%d, %s, %d, 'pending', NOW())",
					$pid,
					$reason,
					$actor_id
				)
			);
		}
		// Schedule immediate processing if not already scheduled.
		if ( ! wp_next_scheduled( self::PROCESS_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::PROCESS_HOOK );
		}
	}

	/**
	 * Process pending invalidation jobs in bounded batches.
	 *
	 * @return int Number of jobs processed.
	 */
	public static function process_batch(): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		$table     = self::table_name();
		$start     = time();
		$processed = 0;

		while ( $processed < self::BATCH_SIZE && ( time() - $start ) < self::RUNTIME_CAP ) {
			$job = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1"
				),
				ARRAY_A
			);
			if ( ! $job ) {
				break;
			}

			$job_id  = (int) $job['id'];
			$post_id = (int) $job['parent_post_id'];
			$reason  = (string) $job['reason'];
			$actor   = (int) $job['actor_id'];

			try {
				Approval_Service::invalidate_direct( $post_id, $reason, $actor );
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status = 'completed', processed_at = NOW() WHERE id = %d",
						$job_id
					)
				);
			} catch ( \Throwable $e ) {
				$retries = (int) $job['retry_count'] + 1;
				$error   = substr( $e->getMessage(), 0, 255 );
				if ( $retries >= self::MAX_RETRIES ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} SET status = 'failed', retry_count = %d, last_error = %s, processed_at = NOW() WHERE id = %d",
							$retries,
							$error,
							$job_id
						)
					);
					error_log( sprintf( '[longevity-core] Invalidation job %d permanently failed for post %d: %s', $job_id, $post_id, $error ) );
				} else {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} SET retry_count = %d, last_error = %s WHERE id = %d",
							$retries,
							$error,
							$job_id
						)
					);
				}
			}
			++$processed;
		}

		// Reschedule if more work remains.
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
		if ( $remaining > 0 ) {
			wp_schedule_single_event( time() + 30, self::PROCESS_HOOK );
		}

		return $processed;
	}

	/**
	 * Get queue statistics for observability.
	 *
	 * @return array{pending: int, completed: int, failed: int, oldest_pending_age_seconds: int}
	 */
	public static function stats(): array {
		global $wpdb;
		$empty = array( 'pending' => 0, 'completed' => 0, 'failed' => 0, 'oldest_pending_age_seconds' => 0 );
		if ( ! self::exists() ) {
			return $empty;
		}
		$table = self::table_name();
		$pending   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
		$completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
		$failed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
		$oldest    = $wpdb->get_var( "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1" );
		return array(
			'pending'                  => $pending,
			'completed'                => $completed,
			'failed'                   => $failed,
			'oldest_pending_age_seconds' => $oldest ? (int) $oldest : 0,
		);
	}

	/** Purge completed jobs older than a given number of days (housekeeping). */
	public static function purge_completed( int $older_than_days = 7 ): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'completed' AND processed_at < %s",
				$cutoff
			)
		);
	}
}
