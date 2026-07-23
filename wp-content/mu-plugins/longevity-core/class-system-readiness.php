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
	/** Build a non-secret readiness report. */
	public static function report(): array {
		$config = Runtime_Config::scoring_model_status();
		$checks = array(
			'database' => self::check( self::database_reachable(), 'ok', 'blocked', 'Database connectivity.' ),
			'migrations' => self::migration_check(),
			'scoring_model' => array( 'status' => $config['valid'] ? 'ok' : 'blocked', 'code' => $config['code'], 'message' => $config['message'] ),
			'freshness' => self::freshness_check(),
			'cron_heartbeat' => self::cron_check(),
			'uploads' => self::uploads_check(),
			'approval_table' => self::check( Approval_Repository::exists(), 'ok', 'blocked', 'Approval snapshot table.' ),
			'audit_table' => self::check( Audit_Log::exists(), 'ok', 'blocked', 'Governance audit table.' ),
			'audit_write_failures' => self::audit_failure_check(),
			'publication_lock' => self::lock_check(),
			'invalidation_queue' => self::queue_check(),
			'mail_transport' => self::external_evidence( 'lel_mail_transport_evidence', 'Mail transport evidence has not been supplied by an operator.' ),
			'last_backup' => self::external_evidence( 'lel_last_backup_evidence', 'Backup evidence has not been supplied by an operator.' ),
			'last_restore_drill' => self::external_evidence( 'lel_last_restore_drill_evidence', 'Restore-drill evidence has not been supplied by an operator.' ),
		);
		$overall = 'ok';
		foreach ( $checks as $check ) {
			if ( 'blocked' === $check['status'] ) {
				$overall = 'blocked';
				break;
			}
			if ( in_array( $check['status'], array( 'degraded', 'unknown_external' ), true ) && 'ok' === $overall ) {
				$overall = 'degraded';
			}
		}
		return array( 'status' => $overall, 'checked_at' => gmdate( DATE_W3C ), 'checks' => $checks );
	}

	/** Migration state including pending versions and lock info. */
	private static function migration_check(): array {
		$current = (int) get_option( 'lel_data_version', 0 );
		$error   = get_option( 'lel_data_migration_error', false );
		if ( $error ) {
			return array( 'status' => 'blocked', 'message' => 'Migration failed.', 'current_version' => $current, 'target_version' => Migrations::CURRENT_VERSION, 'error' => $error );
		}
		if ( $current < Migrations::CURRENT_VERSION ) {
			return array( 'status' => 'blocked', 'message' => sprintf( 'Migrations pending: version %d of %d.', $current, Migrations::CURRENT_VERSION ), 'current_version' => $current, 'target_version' => Migrations::CURRENT_VERSION );
		}
		return array( 'status' => 'ok', 'message' => 'Data migrations current.', 'current_version' => $current );
	}

	/** Audit write failure counter check. */
	private static function audit_failure_check(): array {
		$failures = (int) get_option( 'lel_audit_write_failures', 0 );
		if ( $failures > 0 ) {
			return array( 'status' => 'degraded', 'message' => sprintf( '%d audit write failure(s) recorded.', $failures ), 'failure_count' => $failures );
		}
		return array( 'status' => 'ok', 'message' => 'No audit write failures.' );
	}

	/** Simple check record. */
	private static function check( bool $condition, string $ok, string $failed, string $message ): array {
		return array( 'status' => $condition ? $ok : $failed, 'message' => $message );
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
			return array( 'status' => 'degraded', 'message' => 'Freshness has never completed successfully.' );
		}
		$timestamp = strtotime( $time );
		return array( 'status' => $timestamp && $timestamp >= time() - ( 2 * DAY_IN_SECONDS ) ? 'ok' : 'degraded', 'message' => 'Freshness cycle recency.', 'last_success_at' => $time );
	}

	/** Cron heartbeat. */
	private static function cron_check(): array {
		$heartbeat = (string) get_option( 'lel_cron_heartbeat_at', '' );
		if ( '' === $heartbeat ) {
			return array( 'status' => 'degraded', 'message' => 'No cron heartbeat has been recorded.' );
		}
		$timestamp = strtotime( $heartbeat );
		return array( 'status' => $timestamp && $timestamp >= time() - ( 2 * DAY_IN_SECONDS ) ? 'ok' : 'degraded', 'message' => 'Cron heartbeat recency.', 'last_heartbeat_at' => $heartbeat );
	}

	/** Check upload writability only when the deployment declares it required. */
	private static function uploads_check(): array {
		if ( ! (bool) get_option( 'lel_require_upload_writes', false ) ) {
			return array( 'status' => 'ok', 'message' => 'Runtime upload writes are not required by configuration.' );
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return array( 'status' => 'blocked', 'message' => 'Upload directory status is unavailable.' );
		}
		$uploads = wp_upload_dir( null, false, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array( 'status' => 'blocked', 'message' => 'The required upload directory is unavailable.' );
		}
		return array( 'status' => is_writable( (string) $uploads['basedir'] ) ? 'ok' : 'blocked', 'message' => 'Required upload directory writability.' );
	}

	/** Publication lock health: GET_LOCK support and failure count. */
	private static function lock_check(): array {
		$supported = Publication_Lock::get_lock_supported();
		$failures  = Publication_Lock::failure_count();
		if ( ! $supported ) {
			return array( 'status' => 'blocked', 'message' => 'GET_LOCK is not supported on this database server.', 'lock_failures' => $failures );
		}
		if ( $failures > 0 ) {
			return array( 'status' => 'degraded', 'message' => sprintf( '%d publication lock failure(s) recorded.', $failures ), 'lock_failures' => $failures );
		}
		return array( 'status' => 'ok', 'message' => 'Publication locks operational.' );
	}

	/** Invalidation queue depth and age. */
	private static function queue_check(): array {
		$stats = Invalidation_Queue::stats();
		if ( $stats['failed'] > 0 ) {
			return array( 'status' => 'degraded', 'message' => sprintf( '%d failed invalidation job(s).', $stats['failed'] ), 'queue' => $stats );
		}
		if ( $stats['oldest_pending_age_seconds'] > 300 ) {
			return array( 'status' => 'degraded', 'message' => sprintf( 'Oldest pending invalidation is %ds old.', $stats['oldest_pending_age_seconds'] ), 'queue' => $stats );
		}
		return array( 'status' => 'ok', 'message' => 'Invalidation queue healthy.', 'queue' => $stats );
	}

	/**
	 * Operator-supplied external evidence with structured validation.
	 *
	 * Expected JSON structure:
	 * {type, result, artifact_ref, performed_at, expires_at, actor, environment, release_id}
	 *
	 * Legacy string values are treated as unknown_external until revalidated.
	 */
	private static function external_evidence( string $option, string $message ): array {
		$value = get_option( $option, '' );
		if ( '' === (string) $value ) {
			return array( 'status' => 'unknown_external', 'message' => $message );
		}

		// Structured JSON evidence.
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( ! is_array( $decoded ) ) {
				// Legacy plain-string value: mark as unvalidated.
				return array( 'status' => 'unknown_external', 'message' => 'Legacy evidence format; operator revalidation required.', 'raw_value' => sanitize_text_field( substr( $value, 0, 100 ) ) );
			}
			$value = $decoded;
		}

		if ( ! is_array( $value ) ) {
			return array( 'status' => 'unknown_external', 'message' => 'Malformed evidence; operator revalidation required.' );
		}

		// Validate required fields.
		$result       = (string) ( $value['result'] ?? '' );
		$performed_at = (string) ( $value['performed_at'] ?? '' );
		$expires_at   = (string) ( $value['expires_at'] ?? '' );
		$environment  = (string) ( $value['environment'] ?? '' );

		if ( ! in_array( $result, array( 'ok', 'pass', 'fail', 'error' ), true ) ) {
			return array( 'status' => 'degraded', 'message' => 'Evidence result is missing or invalid.', 'evidence' => $value );
		}
		if ( in_array( $result, array( 'fail', 'error' ), true ) ) {
			return array( 'status' => 'blocked', 'message' => sprintf( 'Evidence reports failure: %s.', $result ), 'evidence' => $value );
		}

		// Validate performed_at is not in the future.
		$performed_ts = strtotime( $performed_at );
		if ( $performed_ts && $performed_ts > time() + 3600 ) {
			return array( 'status' => 'degraded', 'message' => 'Evidence performed_at is in the future.', 'evidence' => $value );
		}

		// Validate expiry.
		if ( '' !== $expires_at ) {
			$expires_ts = strtotime( $expires_at );
			if ( $expires_ts && $expires_ts < time() ) {
				return array( 'status' => 'degraded', 'message' => 'Evidence has expired.', 'evidence' => $value );
			}
		}

		// Validate environment matches current.
		$current_env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( '' !== $environment && $environment !== $current_env ) {
			return array( 'status' => 'degraded', 'message' => sprintf( 'Evidence is for environment "%s" but current is "%s".', $environment, $current_env ), 'evidence' => $value );
		}

		return array( 'status' => 'ok', 'message' => 'Valid structured evidence.', 'evidence' => array(
			'type'         => sanitize_text_field( (string) ( $value['type'] ?? '' ) ),
			'result'       => $result,
			'performed_at' => sanitize_text_field( $performed_at ),
			'expires_at'   => sanitize_text_field( $expires_at ),
			'actor'        => sanitize_text_field( (string) ( $value['actor'] ?? '' ) ),
			'artifact_ref' => sanitize_text_field( (string) ( $value['artifact_ref'] ?? '' ) ),
		) );
	}
}
