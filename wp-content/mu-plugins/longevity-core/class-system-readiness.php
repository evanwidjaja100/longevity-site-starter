<?php
/**
 * Protected production readiness checks.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Reports internal readiness without claiming unknown external controls succeeded. */
final class System_Readiness {
	/** Closed set ordered from ready to internal error. */
	public const CHECK_STATES = array( 'ok', 'degraded', 'unknown_external', 'blocked', 'error' );

	/**
	 * Normalize a check status against the closed enum, failing closed.
	 *
	 * @param string $status Raw status string from a check.
	 */
	public static function normalize_status( string $status ): string {
		return in_array( $status, self::CHECK_STATES, true ) ? $status : 'error';
	}

	/**
	 * Severity rank for a normalized status.
	 *
	 * @param string $status Normalized status.
	 */
	private static function severity( string $status ): int {
		$rank = array_flip( self::CHECK_STATES );
		return (int) $rank[ self::normalize_status( $status ) ];
	}

	/**
	 * Aggregate check records to an overall status by maximum severity.
	 *
	 * @param array $checks Check records, each optionally carrying a 'status' key.
	 */
	public static function aggregate( array $checks ): string {
		if ( array() === $checks ) {
			return 'error';
		}
		$worst = 'ok';
		foreach ( $checks as $check ) {
			$status = self::normalize_status( is_array( $check ) && isset( $check['status'] ) ? (string) $check['status'] : '' );
			if ( self::severity( $status ) > self::severity( $worst ) ) {
				$worst = $status;
			}
		}
		return $worst;
	}

	/** Build a non-secret readiness report. */
	public static function report(): array {
		$checks = array(
			'database'             => self::safely( static fn(): array => self::check( self::database_reachable(), 'ok', 'blocked', 'Database connectivity.' ) ),
			'environment'          => self::safely( static fn(): array => Platform_Requirements::readiness_check() ),
			'migrations'           => self::safely( static fn(): array => self::migration_check() ),
			'scoring_model'        => self::safely(
				static function (): array {
					$config = Runtime_Config::scoring_model_status();
					return array( 'status' => $config['valid'] ? 'ok' : 'blocked', 'code' => $config['code'], 'message' => $config['message'] );
				}
			),
			'freshness'            => self::safely( static fn(): array => self::freshness_check() ),
			'cron_heartbeat'       => self::safely( static fn(): array => self::cron_check() ),
			'worker_heartbeats'    => self::safely( static fn(): array => self::worker_heartbeat_check() ),
			'uploads'              => self::safely( static fn(): array => self::uploads_check() ),
			'approval_table'       => self::safely( static fn(): array => self::check( Approval_Repository::exists(), 'ok', 'blocked', 'Approval snapshot table.' ) ),
			'audit_table'          => self::safely( static fn(): array => self::check( Audit_Log::exists(), 'ok', 'blocked', 'Governance audit table.' ) ),
			'audit_write_failures' => self::safely( static fn(): array => self::audit_failure_check() ),
			'publication_lock'     => self::safely( static fn(): array => self::lock_check() ),
			'invalidation_queue'   => self::safely( static fn(): array => self::queue_check() ),
			'dependency_index'     => self::safely( static fn(): array => self::dependency_index_check() ),
			'contact_rate_limiter' => self::safely( static fn(): array => self::check( Public_Contact::rate_table_exists(), 'ok', 'blocked', 'Contact rate-limit table (public submissions fail closed without it).' ) ),
			'notification_outbox'  => self::safely( static fn(): array => self::notification_outbox_check() ),
			'mail_transport'       => self::safely( static fn(): array => self::store_evidence( 'mail', 'lel_mail_transport_evidence', 'Mail transport evidence has not been supplied by an operator.' ) ),
			'last_backup'          => self::safely( static fn(): array => self::store_evidence( 'backup', 'lel_last_backup_evidence', 'Backup evidence has not been supplied by an operator.' ) ),
			'last_restore_drill'   => self::safely( static fn(): array => self::store_evidence( 'restore', 'lel_last_restore_drill_evidence', 'Restore-drill evidence has not been supplied by an operator.' ) ),
			'release_evidence'     => self::safely( static fn(): array => self::release_evidence_check() ),
		);
		foreach ( $checks as $name => $check ) {
			$raw        = isset( $check['status'] ) ? (string) $check['status'] : '';
			$normalized = self::normalize_status( $raw );
			if ( $normalized !== $raw ) {
				$checks[ $name ]['original_status'] = $raw;
				error_log( sprintf( '[longevity-core] readiness check %s emitted unregistered status %s', $name, '' === $raw ? '(empty)' : $raw ) );
			}
			$checks[ $name ]['status'] = $normalized;
		}
		return array(
			'status'     => self::aggregate( $checks ),
			'checked_at' => gmdate( DATE_W3C ),
			'checks'     => $checks,
		);
	}

	/** Convert exceptions and malformed producer output into an explicit error. */
	private static function safely( callable $producer ): array {
		try {
			$result = $producer();
			return is_array( $result ) ? $result : array( 'status' => 'error', 'message' => 'Readiness producer returned a malformed result.' );
		} catch ( \Throwable $error ) {
			error_log( '[longevity-core] readiness producer failed: ' . $error->getMessage() );
			return array( 'status' => 'error', 'message' => 'Readiness producer failed internally.' );
		}
	}

	/** Migration state including pending versions and lock info. */
	private static function migration_check(): array {
		$current = (int) get_option( 'lel_data_version', 0 );
		$error   = get_option( 'lel_data_migration_error', false );
		if ( $error ) {
			return array(
				'status'          => 'blocked',
				'message'         => 'Migration failed.',
				'current_version' => $current,
				'target_version'  => Migrations::CURRENT_VERSION,
				'error'           => $error,
			);
		}
		if ( $current < Migrations::CURRENT_VERSION ) {
			return array(
				'status'          => 'blocked',
				'message'         => sprintf( 'Migrations pending: version %d of %d.', $current, Migrations::CURRENT_VERSION ),
				'current_version' => $current,
				'target_version'  => Migrations::CURRENT_VERSION,
			);
		}
		return array(
			'status'          => 'ok',
			'message'         => 'Data migrations current.',
			'current_version' => $current,
		);
	}

	/** Audit write failure counter check. */
	private static function audit_failure_check(): array {
		$failures = (int) get_option( 'lel_audit_write_failures', 0 );
		if ( $failures > 0 ) {
			return array(
				'status'        => 'degraded',
				'message'       => sprintf( '%d audit write failure(s) recorded.', $failures ),
				'failure_count' => $failures,
			);
		}
		return array(
			'status'  => 'ok',
			'message' => 'No audit write failures.',
		);
	}

	/**
	 * Simple check record.
	 *
	 * @param bool   $condition Check result.
	 * @param string $ok        Status when true.
	 * @param string $failed    Status when false.
	 * @param string $message   Human-readable message.
	 */
	private static function check( bool $condition, string $ok, string $failed, string $message ): array {
		return array(
			'status'  => $condition ? $ok : $failed,
			'message' => $message,
		);
	}

	/** Database liveness. */
	private static function database_reachable(): bool {
		global $wpdb;
		return isset( $wpdb ) && method_exists( $wpdb, 'get_var' ) && '1' === (string) $wpdb->get_var( 'SELECT 1' );
	}

	/** Freshness cycle recency. */
	private static function freshness_check(): array {
		$last = get_option( 'lel_last_freshness_report', array() );
		$time = is_array( $last ) ? (string) ( $last['last_success_at'] ?? $last['run_at'] ?? '' ) : '';
		if ( '' === $time ) {
			return array(
				'status'  => 'degraded',
				'message' => 'Freshness has never completed successfully.',
			);
		}
		$timestamp = strtotime( $time );
		return array(
			'status'          => $timestamp && $timestamp >= time() - ( 2 * DAY_IN_SECONDS ) ? 'ok' : 'degraded',
			'message'         => 'Freshness cycle recency.',
			'last_success_at' => $time,
		);
	}

	/** Cron heartbeat. */
	private static function cron_check(): array {
		$heartbeat = (string) get_option( 'lel_cron_heartbeat_at', '' );
		if ( '' === $heartbeat ) {
			return array(
				'status'  => 'degraded',
				'message' => 'No cron heartbeat has been recorded.',
			);
		}
		$timestamp = strtotime( $heartbeat );
		return array(
			'status'            => $timestamp && $timestamp >= time() - ( 2 * DAY_IN_SECONDS ) ? 'ok' : 'degraded',
			'message'           => 'Cron heartbeat recency.',
			'last_heartbeat_at' => $heartbeat,
		);
	}

	/** Every required worker must have its own current schedule and heartbeat. */
	private static function worker_heartbeat_check(): array {
		$workers = Freshness::worker_statuses();
		$blocked = array_keys( array_filter( $workers, static fn( array $worker ): bool => 'ok' !== ( $worker['status'] ?? '' ) ) );
		return array(
			'status'  => $blocked ? 'blocked' : 'ok',
			'message' => $blocked ? 'Workers not current: ' . implode( ', ', $blocked ) . '.' : 'All required worker schedules and heartbeats are current.',
			'workers' => $workers,
		);
	}

	/** Check upload writability only when the deployment declares it required. */
	private static function uploads_check(): array {
		if ( ! (bool) get_option( 'lel_require_upload_writes', false ) ) {
			return array(
				'status'  => 'ok',
				'message' => 'Runtime upload writes are not required by configuration.',
			);
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return array(
				'status'  => 'blocked',
				'message' => 'Upload directory status is unavailable.',
			);
		}
		$uploads = wp_upload_dir( null, false, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array(
				'status'  => 'blocked',
				'message' => 'The required upload directory is unavailable.',
			);
		}
		return array(
			'status'  => is_writable( (string) $uploads['basedir'] ) ? 'ok' : 'blocked',
			'message' => 'Required upload directory writability.',
		);
	}

	/** Publication lock health: GET_LOCK support and failure count. */
	private static function lock_check(): array {
		$supported = Publication_Lock::get_lock_supported();
		$failures  = Publication_Lock::failure_count();
		if ( ! $supported ) {
			return array(
				'status'        => 'blocked',
				'message'       => 'GET_LOCK is not supported on this database server.',
				'lock_failures' => $failures,
			);
		}
		if ( $failures > 0 ) {
			return array(
				'status'        => 'degraded',
				'message'       => sprintf( '%d publication lock failure(s) recorded.', $failures ),
				'lock_failures' => $failures,
			);
		}
		return array(
			'status'  => 'ok',
			'message' => 'Publication locks operational.',
		);
	}

	/** Dependency index availability and backfill completion. */
	private static function dependency_index_check(): array {
		if ( ! Dependency_Index::exists() ) {
			return array(
				'status'  => 'blocked',
				'message' => 'Dependency index table is missing; migrations may not have run.',
			);
		}
		$drift = Dependency_Index::verify_drift();
		if ( Dependency_Index::backfill_is_current() && true === ( $drift['valid'] ?? false ) ) {
			return array(
				'status'        => 'ok',
				'message'       => 'Dependency index generation, schema, and source data are current.',
				'backfilled_at' => (string) get_option( 'lel_dependency_index_backfilled_at', '' ),
				'drift'         => $drift,
			);
		}
		return array(
			'status'  => 'blocked',
			'message' => 'Dependency index backfill is missing, stale, or drifted; run `wp longevity dependency backfill`.',
			'drift'   => $drift,
		);
	}

	/** Notification outbox availability and dead-letter visibility. */
	private static function notification_outbox_check(): array {
		$stats = Notification_Outbox::stats();
		if ( empty( $stats['available'] ) ) {
			return array(
				'status'  => 'blocked',
				'message' => 'Notification outbox state is unavailable: ' . (string) ( $stats['error'] ?? 'unknown' ) . '.',
				'outbox'  => $stats,
			);
		}
		if ( $stats['failed'] > 0 ) {
			return array(
				'status'  => 'degraded',
				'message' => sprintf( '%d notification(s) dead-lettered after %d delivery attempts.', $stats['failed'], Notification_Outbox::MAX_ATTEMPTS ),
				'pending' => $stats['pending'],
				'failed'  => $stats['failed'],
			);
		}
		return array(
			'status'  => 'ok',
			'message' => 'Notification outbox operational.',
			'pending' => $stats['pending'],
		);
	}

	/** Invalidation queue depth and age. */
	private static function queue_check(): array {
		$stats = Invalidation_Queue::stats();
		$enqueue_failures = Invalidation_Queue::enqueue_failure_count();
		if ( ! empty( $stats['table_missing'] ) ) {
			return array(
				'status'  => 'blocked',
				'message' => 'Invalidation queue table is missing; migrations may not have run.',
				'queue'   => $stats,
			);
		}
		if ( $enqueue_failures > 0 ) {
			return array(
				'status'  => 'blocked',
				'message' => sprintf( '%d invalidation queue write failure(s) require reconciliation.', $enqueue_failures ),
				'queue'   => $stats,
			);
		}
		if ( $stats['failed'] > 0 ) {
			return array(
				'status'  => 'degraded',
				'message' => sprintf( '%d failed invalidation job(s).', $stats['failed'] ),
				'queue'   => $stats,
			);
		}
		if ( $stats['oldest_pending_age_seconds'] > 300 ) {
			return array(
				'status'  => 'degraded',
				'message' => sprintf( 'Oldest pending invalidation is %ds old.', $stats['oldest_pending_age_seconds'] ),
				'queue'   => $stats,
			);
		}
		return array(
			'status'  => 'ok',
			'message' => 'Invalidation queue healthy.',
			'queue'   => $stats,
		);
	}

	/**
	 * Prefer an append-only store record. Legacy mutable options are visible
	 * but can never satisfy readiness.
	 *
	 * @param string $type    Evidence type registered in Evidence_Store.
	 * @param string $option  Legacy option name.
	 * @param string $message Human-readable description when nothing is present.
	 */
	private static function store_evidence( string $type, string $option, string $message ): array {
		if ( class_exists( Evidence_Store::class ) && Evidence_Store::exists() ) {
			$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
			$record      = Evidence_Store::latest_valid_matching( $type, $environment );
			if ( is_array( $record ) ) {
				return self::validate_store_record( $record );
			}
			$latest = Evidence_Store::latest( $type );
			if ( is_array( $latest ) ) {
				return array(
					'status'  => $environment === (string) ( $latest['environment'] ?? '' ) ? 'error' : 'blocked',
					'message' => 'No valid active evidence record matches the current environment.',
				);
			}
		}
		$legacy = get_option( $option, '' );
		return array(
			'status'  => 'unknown_external',
			'message' => '' === (string) $legacy ? $message : 'Legacy evidence exists but cannot satisfy readiness until re-verified into the append-only store.',
		);
	}

	/**
	 * Release-scoped evidence is never satisfied by a legacy option value; it
	 * requires a structured store record bound to a release SHA and checksum.
	 */
	private static function release_evidence_check(): array {
		$identity = Evidence_Store::runtime_release_identity();
		if ( empty( $identity['valid'] ) ) {
			return array(
				'status'  => 'error',
				'message' => 'Immutable deployed environment, source SHA, and artifact SHA-256 are missing or malformed.',
			);
		}
		if ( class_exists( Evidence_Store::class ) && Evidence_Store::exists() ) {
			$record = Evidence_Store::latest_valid_matching( 'release-artifact', (string) $identity['environment'], (string) $identity['release_sha'], (string) $identity['artifact_checksum'] );
			if ( is_array( $record ) ) {
				$validated = self::validate_store_record( $record );
				if ( 'ok' !== $validated['status'] ) {
					return $validated;
				}
				return $validated;
			}
			if ( is_array( Evidence_Store::latest( 'release-artifact' ) ) ) {
				return array( 'status' => 'blocked', 'message' => 'No valid active release evidence matches the deployed environment, source SHA, and artifact checksum.' );
			}
		}
		return array(
			'status'  => 'unknown_external',
			'message' => 'No release-artifact evidence recorded for the current release.',
		);
	}

	/**
	 * Map a stored evidence record to a readiness status, failing closed.
	 *
	 * @param array<string, mixed> $record Stored evidence row.
	 */
	private static function validate_store_record( array $record ): array {
		$result     = (string) ( $record['result'] ?? '' );
		$expires_at = (string) ( $record['expires_at'] ?? '' );
		$record_id  = (int) ( $record['id'] ?? 0 );
		if ( $record_id < 1 || true !== Evidence_Store::validate_stored_record( $record ) || Evidence_Store::is_superseded( $record_id ) ) {
			return array( 'status' => 'error', 'message' => 'Evidence integrity, attachment, or supersession validation failed.', 'evidence_id' => $record_id );
		}
		if ( in_array( $result, array( 'fail', 'error' ), true ) ) {
			return array( 'status' => 'blocked', 'message' => sprintf( 'Evidence reports failure: %s.', $result ), 'evidence_id' => $record_id );
		}
		if ( '' !== $expires_at ) {
			$expires_ts = strtotime( $expires_at . ' UTC' );
			if ( false === $expires_ts || $expires_ts <= time() ) {
				return array( 'status' => 'blocked', 'message' => 'Evidence has expired.', 'evidence_id' => $record_id );
			}
		}
		$produced_at = strtotime( (string) ( $record['produced_at'] ?? '' ) . ' UTC' );
		if ( false === $produced_at || $produced_at > time() + HOUR_IN_SECONDS ) {
			return array( 'status' => 'error', 'message' => 'Evidence production time is missing, invalid, or in the future.', 'evidence_id' => $record_id );
		}
		$current_env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
		if ( $current_env !== (string) ( $record['environment'] ?? '' ) ) {
			return array( 'status' => 'blocked', 'message' => 'Evidence was produced for a different environment.', 'evidence_id' => $record_id );
		}
		return array( 'status' => 'ok', 'message' => 'Valid structured evidence.', 'evidence_id' => $record_id );
	}

}
