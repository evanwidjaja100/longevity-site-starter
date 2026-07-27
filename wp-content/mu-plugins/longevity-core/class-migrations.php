<?php
/**
 * Idempotent internal data-version migrations.
 *
 * Migrations run exclusively via the `wp longevity migrate` CLI command,
 * never during ordinary web requests. A global lock prevents concurrent
 * execution and data migrations are chunked for resumability.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Advances additive, restart-safe governance migrations via explicit CLI invocation. */
final class Migrations {
	public const CURRENT_VERSION = 10;

	/** Lock time-to-live in seconds. */
	private const LOCK_TTL = 300;

	/** Batch size for chunked data migrations. */
	private const BATCH_SIZE = 200;

	/** Register the version check. Migrations must be run explicitly via CLI. */
	public static function init(): void {
		// Web-request auto-migration is intentionally disabled for production safety.
		// Run migrations explicitly via `wp longevity migrate` before deploying.
	}

	/**
	 * Apply only missing versions under a global lock.
	 * Called exclusively from the CLI migrate command.
	 *
	 * @param bool $force Override a stale lock.
	 * @return array{success: bool, migrated: list<int>, error: string}
	 */
	public static function run_migrations( bool $force = false ): array {
		if ( ! self::wordpress_ready() ) {
			return array( 'success' => false, 'migrated' => array(), 'error' => 'WordPress is not ready.' );
		}
		$current = (int) get_option( 'lel_data_version', 0 );
		if ( $current >= self::CURRENT_VERSION ) {
			return array( 'success' => true, 'migrated' => array(), 'error' => '' );
		}
		if ( ! self::acquire_lock( $force ) ) {
			$lock = get_option( 'lel_migration_lock', array() );
			return array( 'success' => false, 'migrated' => array(), 'error' => sprintf( 'Migration lock held by PID %s (expires %s). Use --force to override.', $lock['owner'] ?? 'unknown', gmdate( DATE_W3C, (int) ( $lock['expires_at'] ?? 0 ) ) ) );
		}
		$migrated = array();
		Meta_Authorization::enter_trusted_scope();
		try {
			for ( $version = $current + 1; $version <= self::CURRENT_VERSION; ++$version ) {
				self::run_version( $version );
				self::validate_postconditions( $version );
				update_option( 'lel_data_version', $version, false );
				delete_option( 'lel_data_migration_error' );
				delete_option( 'lel_migration_cursor_' . $version );
				$migrated[] = $version;
				Audit_Log::record( 'migration_completed', 'system', 0, array( 'version' => $version ), 0, 'migration' );
			}
		} catch ( \Throwable $error ) {
			$failed_version = $current + count( $migrated ) + 1;
			update_option( 'lel_data_migration_error', array( 'version' => $failed_version, 'time' => gmdate( DATE_W3C ), 'message' => substr( $error->getMessage(), 0, 255 ) ), false );
			Audit_Log::record( 'migration_failed', 'system', 0, array( 'version' => $failed_version, 'error_class' => get_class( $error ) ), 0, 'migration' );
			Logger::error( 'migration_failed', array( 'version' => $failed_version, 'message' => $error->getMessage() ) );
			return array( 'success' => false, 'migrated' => $migrated, 'error' => sprintf( 'Migration %d failed: %s', $failed_version, $error->getMessage() ) );
		} finally {
			Meta_Authorization::exit_trusted_scope();
			self::release_lock();
		}
		return array( 'success' => true, 'migrated' => $migrated, 'error' => '' );
	}

	/**
	 * Run pending migrations on web requests when WordPress is ready.
	 *
	 * Checks installation state before touching core tables. Safe to call
	 * repeatedly; migrations are idempotent and version-gated.
	 */
	public static function maybe_run(): void {
		if ( ! self::wordpress_ready() ) {
			return;
		}
		if ( (int) get_option( 'lel_data_version', 0 ) >= self::CURRENT_VERSION ) {
			return;
		}
		self::run_migrations( false );
	}

	/** Whether WordPress has finished creating the tables used by MU-plugin hooks. */
	public static function wordpress_ready(): bool {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return false;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) || empty( $wpdb->options ) ) {
			return false;
		}
		$options_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->options ) );
		return $options_table === $wpdb->options;
	}

	/** Whether migrations are pending (for readiness reporting). */
	public static function is_pending(): bool {
		return (int) get_option( 'lel_data_version', 0 ) < self::CURRENT_VERSION;
	}

	/** Current migration error state. */
	public static function error_state(): ?array {
		$error = get_option( 'lel_data_migration_error', false );
		return is_array( $error ) ? $error : null;
	}

	/** Acquire the global migration lock atomically via MySQL GET_LOCK. */
	private static function acquire_lock( bool $force = false ): bool {
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			$result = $wpdb->query( "SELECT GET_LOCK('lel_migration', 0)" );
			if ( 1 === (int) $result ) {
				update_option( 'lel_migration_lock', array(
					'owner'       => function_exists( 'getmypid' ) ? getmypid() : 0,
					'acquired_at' => time(),
					'expires_at'  => time() + self::LOCK_TTL,
				), false );
				return true;
			}
		}
		$lock = get_option( 'lel_migration_lock', null );
		if ( is_array( $lock ) && isset( $lock['expires_at'] ) && (int) $lock['expires_at'] > time() && ! $force ) {
			return false;
		}
		if ( $force && is_array( $lock ) && isset( $lock['expires_at'] ) && (int) $lock['expires_at'] > time() ) {
			Audit_Log::record( 'migration_lock_forced', 'system', 0, array( 'previous_owner' => $lock['owner'] ?? 0 ), 0, 'migration' );
		}
		update_option( 'lel_migration_lock', array(
			'owner'       => function_exists( 'getmypid' ) ? getmypid() : 0,
			'acquired_at' => time(),
			'expires_at'  => time() + self::LOCK_TTL,
		), false );
		return true;
	}

	/** Release the global migration lock. */
	private static function release_lock(): void {
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			$wpdb->query( "SELECT RELEASE_LOCK('lel_migration')" );
		}
		delete_option( 'lel_migration_lock' );
	}

	/** Extend the lock TTL during long-running batches. */
	private static function extend_lock(): void {
		$lock = get_option( 'lel_migration_lock', null );
		if ( is_array( $lock ) ) {
			$lock['expires_at'] = time() + self::LOCK_TTL;
			update_option( 'lel_migration_lock', $lock, false );
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
		if ( 7 === $version ) {
			Roles::register();
		}
		if ( 8 === $version ) {
			self::load_db_delta();
			Audit_Log::install();
			self::backfill_audit_sequence();
			Roles::reconcile();
		}
		if ( 9 === $version ) {
			self::load_db_delta();
			Dependency_Index::install();
			Invalidation_Queue::install();
			Public_Contact::install_rate_table();
		}
		if ( 10 === $version ) {
			self::load_db_delta();
			Audit_Log::install();
			// Explicitly add the fork-preventing unique constraint to existing
			// tables (dbDelta does not reliably add UNIQUE keys in place).
			if ( ! Audit_Log::ensure_fork_constraint() ) {
				throw new \RuntimeException( 'Failed to enforce audit-chain fork constraint; the audit table may contain a fork and requires manual remediation.' );
			}
			add_option( 'lel_audit_schema_version', Audit_Log::SCHEMA_VERSION, '', false );
		}
	}

	/** Validate schema postconditions after each migration version. */
	private static function validate_postconditions( int $version ): void {
		if ( 3 === $version ) {
			if ( ! Audit_Log::exists() ) {
				throw new \RuntimeException( 'Migration 3 postcondition failed: audit table missing.' );
			}
		}
		if ( 8 === $version ) {
			if ( ! Audit_Log::ensure_fork_constraint() ) {
				throw new \RuntimeException( 'Migration 8 postcondition failed: fork constraint missing.' );
			}
		}
		if ( 9 === $version ) {
			if ( ! Invalidation_Queue::exists() ) {
				throw new \RuntimeException( 'Migration 9 postcondition failed: invalidation queue table missing.' );
			}
		}
		if ( 10 === $version ) {
			if ( ! Audit_Log::ensure_fork_constraint() ) {
				throw new \RuntimeException( 'Migration 10 postcondition failed: fork constraint missing.' );
			}
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

	/** Existing status strings lack immutable fingerprints and require reapproval. Chunked for resumability. */
	private static function mark_legacy_approvals_unbound(): void {
		$cursor = (int) get_option( 'lel_migration_cursor_5', 0 );
		$legacy = array(
			'fact_check_status'           => array( 'complete' ),
			'medical_review_status'       => array( 'complete' ),
			'testing_status'              => array( 'complete', 'approved' ),
			'affiliate_disclosure_status' => array( 'complete', 'approved' ),
			'editorial_approval_status'   => array( 'ready', 'published' ),
		);
		$total_marked = (int) get_option( 'lel_legacy_approvals_marked', 0 );
		while ( true ) {
			$posts = get_posts( array(
				'post_type'      => array( 'post', 'review' ),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => self::BATCH_SIZE,
				'offset'         => $cursor,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			) );
			if ( empty( $posts ) ) {
				break;
			}
			foreach ( $posts as $post_id ) {
				foreach ( $legacy as $key => $completed_values ) {
					if ( in_array( (string) get_post_meta( (int) $post_id, $key, true ), $completed_values, true ) && ! Approval_Repository::current( (int) $post_id, self::approval_type_for_status( $key ) ) ) {
						update_post_meta( (int) $post_id, $key, 'legacy_unbound' );
						++$total_marked;
					}
				}
			}
			$cursor += self::BATCH_SIZE;
			update_option( 'lel_migration_cursor_5', $cursor, false );
			update_option( 'lel_legacy_approvals_marked', $total_marked, false );
			self::extend_lock();
		}
		update_option( 'lel_legacy_approvals_marked', $total_marked, false );
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

	/** Assign sequence numbers to pre-existing audit rows that lack them. Chunked with keyset pagination. */
	private static function backfill_audit_sequence(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! Audit_Log::exists() ) {
			return;
		}
		$table  = Audit_Log::table_name();
		$cursor = (int) get_option( 'lel_migration_cursor_8', 0 );
		$seq    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sequence), 0) FROM {$table} WHERE sequence > 0" );
		while ( true ) {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE sequence = 0 AND id > %d ORDER BY id ASC LIMIT %d", $cursor, self::BATCH_SIZE ) );
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row_id ) {
				++$seq;
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET sequence = %d WHERE id = %d", $seq, (int) $row_id ) );
			}
			$cursor = (int) end( $rows );
			update_option( 'lel_migration_cursor_8', $cursor, false );
			self::extend_lock();
		}
	}
}
