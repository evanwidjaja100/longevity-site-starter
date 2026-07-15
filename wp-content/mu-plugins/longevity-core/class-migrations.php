<?php
/**
 * Idempotent internal data-version migrations.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Advances non-destructive metadata and option migrations once per version. */
final class Migrations {
	public const CURRENT_VERSION = 2;

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
				if ( function_exists( 'error_log' ) ) {
					error_log( sprintf( 'Longevity Core migration %d completed.', $version ) );
				}
			} catch ( \Throwable $error ) {
				update_option( 'lel_data_migration_error', array( 'version' => $version, 'time' => gmdate( DATE_W3C ) ), false );
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
	}
}
