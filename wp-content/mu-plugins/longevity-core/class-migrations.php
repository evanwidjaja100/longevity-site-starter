<?php
/**
 * Idempotent internal data-version migrations.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Advances additive, restart-safe governance migrations. */
final class Migrations {
	public const CURRENT_VERSION = 6;

	/** Register the version check. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'maybe_run' ), 1 );
	}

	/** Apply only missing versions and record success after each completed step. */
	public static function maybe_run(): void {
		$current = (int) get_option( 'lel_data_version', 0 );
		if ( $current >= self::CURRENT_VERSION ) {
			return;
		}
		for ( $version = $current + 1; $version <= self::CURRENT_VERSION; ++$version ) {
			try {
				self::run_version( $version );
				update_option( 'lel_data_version', $version, false );
				delete_option( 'lel_data_migration_error' );
				Audit_Log::record( 'migration_completed', 'system', 0, array( 'version' => $version ), 0, 'migration' );
			} catch ( \Throwable $error ) {
				update_option( 'lel_data_migration_error', array( 'version' => $version, 'time' => gmdate( DATE_W3C ) ), false );
				Audit_Log::record( 'migration_failed', 'system', 0, array( 'version' => $version, 'error_class' => get_class( $error ) ), 0, 'migration' );
				if ( function_exists( 'error_log' ) ) {
					error_log( sprintf( 'Longevity Core migration %d failed.', $version ) );
				}
				return;
			}
		}
	}

	/** Execute an individual restart-safe migration. */
	private static function run_version( int $version ): void {
		if ( 1 === $version ) {
			add_option( 'lel_last_freshness_report', array(), '', false );
			add_option( 'lel_freshness_batch_size', 100, '', false );
		}
		if ( 2 === $version ) {
			add_option( 'lel_rankings_cache_version', '1', '', false );
			add_option( 'lel_public_results_schema_version', '1.0.0', '', false );
		}
		if ( 3 === $version ) {
			self::load_db_delta();
			Approval_Repository::install();
			Audit_Log::install();
			add_option( 'lel_approval_schema_version', Approval_Fingerprint::SCHEMA_VERSION, '', false );
			add_option( 'lel_audit_schema_version', Audit_Log::SCHEMA_VERSION, '', false );
		}
		if ( 4 === $version ) {
			add_option( 'lel_contact_retention_days', 90, '', false );
			add_option( 'lel_contact_rate_key_version', 1, '', false );
			add_option( 'lel_affiliate_verification_max_age_days', 365, '', false );
			self::mark_legacy_reviewer_verifications();
		}
		if ( 5 === $version ) {
			self::mark_legacy_approvals_unbound();
		}
		if ( 6 === $version ) {
			add_option( 'lel_freshness_cycle_started_at', '', '', false );
			add_option( 'lel_freshness_last_cycle_completed_at', '', '', false );
			add_option( 'lel_cron_heartbeat_at', '', '', false );
			add_option( 'lel_require_upload_writes', false, '', false );
		}
	}

	/** Ensure WordPress's additive schema helper is available. */
	private static function load_db_delta(): void {
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			throw new \RuntimeException( 'dbDelta is unavailable.' );
		}
	}

	/** Legacy self-editable verification can never be silently trusted. */
	private static function mark_legacy_reviewer_verifications(): void {
		$users = get_users( array( 'meta_key' => 'credential_verification_status', 'meta_value' => 'verified', 'fields' => 'ids' ) );
		$count = 0;
		foreach ( $users as $user_id ) {
			if ( ! get_user_meta( (int) $user_id, 'credential_verified_by_user_id', true ) ) {
				update_user_meta( (int) $user_id, 'credential_verification_status', 'legacy_unbound' );
				++$count;
			}
		}
		update_option( 'lel_legacy_reviewer_verifications_marked', $count, false );
	}

	/** Existing status strings lack immutable fingerprints and require reapproval. */
	private static function mark_legacy_approvals_unbound(): void {
		$posts = get_posts( array( 'post_type' => array( 'post', 'review' ), 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ) );
		$count = 0;
		$legacy = array(
			'fact_check_status'           => array( 'complete' ),
			'medical_review_status'       => array( 'complete' ),
			'testing_status'              => array( 'complete', 'approved' ),
			'affiliate_disclosure_status' => array( 'complete', 'approved' ),
			'editorial_approval_status'   => array( 'ready', 'published' ),
		);
		foreach ( $posts as $post_id ) {
			foreach ( $legacy as $key => $completed_values ) {
				if ( in_array( (string) get_post_meta( (int) $post_id, $key, true ), $completed_values, true ) && ! Approval_Repository::current( (int) $post_id, self::approval_type_for_status( $key ) ) ) {
					update_post_meta( (int) $post_id, $key, 'legacy_unbound' );
					++$count;
				}
			}
		}
		update_option( 'lel_legacy_approvals_marked', $count, false );
	}

	/** Map compatibility statuses to snapshot types. */
	private static function approval_type_for_status( string $key ): string {
		return array(
			'fact_check_status'           => 'fact_check',
			'medical_review_status'       => 'medical',
			'testing_status'              => 'testing',
			'affiliate_disclosure_status' => 'commercial',
			'editorial_approval_status'   => 'editorial',
		)[ $key ] ?? '';
	}
}
