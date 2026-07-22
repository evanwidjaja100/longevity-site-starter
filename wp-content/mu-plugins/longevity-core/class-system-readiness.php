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
			'migrations' => self::check( (int) get_option( 'lel_data_version', 0 ) >= Migrations::CURRENT_VERSION && ! get_option( 'lel_data_migration_error', false ), 'ok', 'blocked', 'Data migrations.' ),
			'scoring_model' => array( 'status' => $config['valid'] ? 'ok' : 'blocked', 'code' => $config['code'], 'message' => $config['message'] ),
			'freshness' => self::freshness_check(),
			'cron_heartbeat' => self::cron_check(),
			'uploads' => self::uploads_check(),
			'approval_table' => self::check( Approval_Repository::exists(), 'ok', 'blocked', 'Approval snapshot table.' ),
			'audit_table' => self::check( Audit_Log::exists(), 'ok', 'blocked', 'Governance audit table.' ),
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

	/** Operator-supplied external evidence. */
	private static function external_evidence( string $option, string $message ): array {
		$value = get_option( $option, '' );
		return '' === (string) $value
			? array( 'status' => 'unknown_external', 'message' => $message )
			: array( 'status' => 'ok', 'message' => 'Operator evidence supplied.', 'evidence_at' => sanitize_text_field( (string) $value ) );
	}
}
