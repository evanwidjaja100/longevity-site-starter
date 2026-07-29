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
	public const CURRENT_VERSION = 17;

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
	 * Apply only missing versions under a global advisory lock.
	 * Called exclusively from the CLI migrate command.
	 *
	 * Fail-closed: any non-acquired lock state (contention, error, or a
	 * database without GET_LOCK support) refuses to run migrations. GET_LOCK
	 * releases automatically when the holding connection closes, so there is
	 * no stale-lock override.
	 *
	 * @param bool $force Deprecated; ignored. Advisory locks cannot go stale.
	 * @return array{success: bool, migrated: list<int>, error: string, lock_state: string, lock_release_state?: string}
	 */
	public static function run_migrations( bool $force = false ): array {
		unset( $force );
		if ( ! self::wordpress_ready() ) {
			return array(
				'success'    => false,
				'migrated'   => array(),
				'error'      => 'WordPress is not ready.',
				'lock_state' => '',
			);
		}
		$observed = (int) get_option( 'lel_data_version', 0 );
		$lock_name  = Advisory_Lock::namespaced_name( 'migration' );
		$lock_state = Advisory_Lock::acquire( $lock_name, 0 );
		if ( Advisory_Lock::ACQUIRED !== $lock_state ) {
			Logger::error(
				'migration_lock_refused',
				array(
					'lock_state'   => $lock_state,
					'from_version' => $observed,
					'to_version'   => self::CURRENT_VERSION,
				)
			);
			return array(
				'success'    => false,
				'migrated'   => array(),
				'error'      => self::lock_refusal_message( $lock_state ),
				'lock_state' => $lock_state,
			);
		}
		// The pre-lock value is only an optimization. Another runner may have
		// advanced it while this connection waited for the advisory lock.
		$current = (int) get_option( 'lel_data_version', 0 );
		// Informational only; the advisory lock is the sole mutual-exclusion primitive.
		update_option(
			'lel_migration_lock',
			array(
				'owner'       => function_exists( 'getmypid' ) ? getmypid() : 0,
				'acquired_at' => time(),
				'expires_at'  => time() + self::LOCK_TTL,
			),
			false
		);
		$started_at = gmdate( DATE_W3C );
		Logger::info(
			'migration_run_started',
			array(
				'from_version' => $current,
				'to_version'   => self::CURRENT_VERSION,
				'lock_state'   => $lock_state,
				'started_at'   => $started_at,
			)
		);
		$migrated = array();
		$result   = array(
			'success'    => true,
			'migrated'   => array(),
			'error'      => '',
			'lock_state' => Advisory_Lock::ACQUIRED,
		);
		Meta_Authorization::enter_trusted_scope();
		try {
			if ( $current >= self::CURRENT_VERSION ) {
				self::validate_postconditions( self::CURRENT_VERSION );
			}
			for ( $version = $current + 1; $version <= self::CURRENT_VERSION; ++$version ) {
				self::run_version( $version );
				self::validate_postconditions( $version );
				self::write_data_version( $version );
				delete_option( 'lel_data_migration_error' );
				delete_option( 'lel_migration_cursor_' . $version );
				$migrated[] = $version;
				Audit_Log::record( 'migration_completed', 'system', 0, array( 'version' => $version ), 0, 'migration' );
				Logger::info( 'migration_applied', array( 'version' => $version ) );
			}
		} catch ( \Throwable $error ) {
			$failed_version = $current >= self::CURRENT_VERSION ? self::CURRENT_VERSION : $current + count( $migrated ) + 1;
			update_option(
				'lel_data_migration_error',
				array(
					'version' => $failed_version,
					'time'    => gmdate( DATE_W3C ),
					'message' => substr( $error->getMessage(), 0, 255 ),
				),
				false
			);
			Audit_Log::record(
				'migration_failed',
				'system',
				0,
				array(
					'version'     => $failed_version,
					'error_class' => get_class( $error ),
				),
				0,
				'migration'
			);
			Logger::error(
				'migration_failed',
				array(
					'version' => $failed_version,
					'message' => $error->getMessage(),
				)
			);
			$result = array(
				'success'    => false,
				'migrated'   => $migrated,
				'error'      => sprintf( 'Migration %d failed: %s', $failed_version, $error->getMessage() ),
				'lock_state' => Advisory_Lock::ACQUIRED,
			);
		} finally {
			Meta_Authorization::exit_trusted_scope();
			$release_state = Advisory_Lock::release( $lock_name );
			if ( Advisory_Lock::RELEASED !== $release_state ) {
				Logger::error(
					'migration_lock_release_failed',
					array(
						'lock_state'   => $release_state,
						'from_version' => $current,
						'to_version'   => self::CURRENT_VERSION,
					)
				);
			}
			delete_option( 'lel_migration_lock' );
		}
		$result['migrated']           = $migrated;
		$result['lock_release_state'] = $release_state;
		Logger::info(
			'migration_run_completed',
			array(
				'success'            => $result['success'],
				'migrated'           => $migrated,
				'now_at'             => (int) get_option( 'lel_data_version', 0 ),
				'started_at'         => $started_at,
				'ended_at'           => gmdate( DATE_W3C ),
				'lock_release_state' => $release_state,
			)
		);
		return $result;
	}

	/** Human-readable fail-closed explanation for each refused lock state. */
	private static function lock_refusal_message( string $state ): string {
		if ( Advisory_Lock::CONTENDED === $state ) {
			return 'Migration lock is held by another process. It releases automatically when that process finishes or its database connection closes; retry shortly.';
		}
		if ( Advisory_Lock::UNSUPPORTED === $state ) {
			return 'This database does not support advisory locks (GET_LOCK); migrations are refused because concurrent runs cannot be excluded safely.';
		}
		return 'Advisory lock acquisition returned an error; migrations are refused (fail-closed).';
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

	/** Refresh the informational lock option during long-running batches. */
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
		if ( 11 === $version ) {
			self::load_db_delta();
			Override_Intent::install();
		}
		if ( 12 === $version ) {
			self::load_db_delta();
			Invalidation_Queue::install();
			// Collapse duplicate open jobs, then enforce single-open-job
			// uniqueness via explicit ALTER (dbDelta does not reliably add
			// UNIQUE keys in place).
			if ( ! Invalidation_Queue::ensure_open_uniqueness() ) {
				throw new \RuntimeException( 'Failed to enforce single-open-job uniqueness on the invalidation queue; duplicate open jobs require manual remediation.' );
			}
		}
		if ( 13 === $version ) {
			self::load_db_delta();
			Notification_Outbox::install();
		}
		if ( 14 === $version ) {
			self::load_db_delta();
			Evidence_Store::install();
		}
		if ( 15 === $version ) {
			self::load_db_delta();
			Override_Intent::install();
		}
		if ( 16 === $version ) {
			self::load_db_delta();
			Approval_Repository::install();
			Audit_Log::install();
			Dependency_Index::install();
			Invalidation_Queue::install();
			Public_Contact::install_rate_table();
			Override_Intent::install();
			Notification_Outbox::install();
			Evidence_Store::install();
			if ( ! Audit_Log::ensure_fork_constraint() || ! Audit_Log::ensure_idempotency_constraint() || ! Invalidation_Queue::ensure_open_uniqueness() ) {
				throw new \RuntimeException( 'Migration 16 could not enforce governance integrity constraints.' );
			}
		}
		if ( 17 === $version ) {
			self::load_db_delta();
			Approval_Repository::install();
			if ( ! Approval_Repository::ensure_activation_columns() ) {
				throw new \RuntimeException( 'Migration 17 could not add approval activation columns; the approval table requires manual remediation.' );
			}
			Approval_Repository::backfill_legacy_activation();
		}
	}

	/** Validate schema postconditions after each migration version. */
	private static function validate_postconditions( int $version ): void {
		foreach ( self::schema_contracts( $version ) as $table => $contract ) {
			self::validate_table_contract( $version, $table, $contract['columns'], $contract['indexes'] );
		}
	}

	/** Complete custom-table contracts introduced or repaired by a version. */
	private static function schema_contracts( int $version ): array {
		global $wpdb;
		$contracts = array(
			'approval' => array(
				'table'   => Approval_Repository::table_name(),
				'columns' => array( 'id', 'post_id', 'approval_type', 'approval_status', 'revision_id', 'content_hash', 'governed_meta_hash', 'dependency_hash', 'combined_hash', 'approver_user_id', 'approved_at', 'schema_version', 'payload_json', 'invalidated_at', 'invalidated_by_user_id', 'invalidation_reason', 'supersedes_approval_id' ),
				'indexes' => array( 'PRIMARY' => true, 'post_type_status' => false, 'post_approved' => false, 'combined_hash' => false, 'approver_approved' => false ),
			),
			'audit' => array(
				'table'   => Audit_Log::table_name(),
				'columns' => array( 'id', 'sequence', 'occurred_at', 'event_type', 'actor_user_id', 'object_type', 'object_id', 'request_id', 'source_channel', 'payload_json', 'previous_event_hash', 'event_hash', 'idempotency_key', 'schema_version' ),
				'indexes' => array( 'PRIMARY' => true, 'sequence' => true, 'previous_event_hash' => true, 'idempotency_key' => true, 'object_time' => false, 'actor_time' => false, 'event_type' => false ),
			),
			'audit_sequence' => array(
				'table'   => Audit_Log::sequence_table_name(),
				'columns' => array( 'id', 'current_value' ),
				'indexes' => array( 'PRIMARY' => true ),
			),
			'dependency' => array(
				'table'   => Dependency_Index::table_name(),
				'columns' => array( 'id', 'dependency_type', 'dependency_id', 'parent_post_id', 'created_at' ),
				'indexes' => array( 'PRIMARY' => true, 'dep_parent' => true, 'parent_lookup' => false, 'dep_lookup' => false ),
			),
			'queue' => array(
				'table'   => Invalidation_Queue::table_name(),
				'columns' => array( 'id', 'parent_post_id', 'reason', 'actor_id', 'status', 'retry_count', 'open_marker', 'lease_owner', 'lease_expires_at', 'created_at', 'processed_at', 'last_error', 'audit_event_id' ),
				'indexes' => array( 'PRIMARY' => true, 'uniq_open_parent' => true, 'status_created' => false, 'lease_expiry' => false ),
			),
			'rate' => array(
				'table'   => $wpdb->prefix . 'lel_rate_limits',
				'columns' => array( 'rate_key', 'hit_count', 'expires_at' ),
				'indexes' => array( 'PRIMARY' => true, 'expires_at' => false ),
			),
			'override' => array(
				'table'   => Override_Intent::table_name(),
				'columns' => array( 'id', 'request_id', 'post_id', 'previous_status', 'requested_status', 'user_id', 'capability_snapshot', 'fingerprint', 'approval_state', 'state', 'reason', 'channel', 'source_sha', 'plugin_version', 'requested_at', 'authorized_at', 'applied_at', 'failed_at', 'compensated_at', 'result', 'expires_at' ),
				'indexes' => array( 'PRIMARY' => true, 'uniq_request' => true, 'post_state' => false ),
			),
			'outbox' => array(
				'table'   => Notification_Outbox::table_name(),
				'columns' => array( 'id', 'notification_type', 'object_id', 'dedupe_key', 'recipient', 'payload_json', 'status', 'attempts', 'last_error', 'lease_owner', 'lease_expires_at', 'next_attempt_at', 'created_at', 'sent_at' ),
				'indexes' => array( 'PRIMARY' => true, 'dedupe_key' => true, 'state_due' => false, 'object_type' => false ),
			),
			'evidence' => array(
				'table'   => Evidence_Store::table_name(),
				'columns' => array( 'id', 'evidence_type', 'release_sha', 'artifact_checksum', 'result', 'environment', 'produced_at', 'expires_at', 'payload_json', 'record_hash', 'recorded_by', 'recorded_at' ),
				'indexes' => array( 'PRIMARY' => true, 'type_id' => false, 'release_sha' => false ),
			),
		);
		if ( 17 === $version ) {
			$contracts['approval']['columns']                   = array_merge( $contracts['approval']['columns'], array( 'audit_event_id', 'activated_at', 'activation_error' ) );
			$contracts['approval']['indexes']['approval_state'] = false;
			$contracts['approval']['indexes']['approval_audit'] = false;
		}
		$names = array(
			3  => array( 'approval', 'audit', 'audit_sequence' ),
			8  => array( 'audit', 'audit_sequence' ),
			9  => array( 'dependency', 'queue', 'rate' ),
			10 => array( 'audit', 'audit_sequence' ),
			11 => array( 'override' ),
			12 => array( 'queue', 'audit' ),
			13 => array( 'outbox' ),
			14 => array( 'evidence' ),
			15 => array( 'override' ),
			16 => array_keys( $contracts ),
			17 => array_keys( $contracts ),
		)[ $version ] ?? array();

		$result = array();
		foreach ( $names as $name ) {
			$result[ $contracts[ $name ]['table'] ] = $contracts[ $name ];
		}
		return $result;
	}

	/** Assert every required column and named index without performing DDL. */
	private static function validate_table_contract( int $version, string $table, array $columns, array $indexes ): void {
		global $wpdb;
		foreach ( $columns as $column ) {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, $column ) );
			if ( 1 !== (int) $count ) {
				throw new \RuntimeException( sprintf( 'Migration %d postcondition failed: %s.%s column missing.', $version, $table, $column ) );
			}
		}
		foreach ( $indexes as $index => $unique ) {
			$sql = 'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s';
			if ( $unique ) {
				$sql .= ' AND NON_UNIQUE = 0';
			}
			$count = $wpdb->get_var( $wpdb->prepare( $sql, $table, $index ) );
			if ( (int) $count <= 0 ) {
				throw new \RuntimeException( sprintf( 'Migration %d postcondition failed: %s.%s index missing.', $version, $table, $index ) );
			}
		}
	}

	/** Advance the migration version only when the durable reread confirms it. */
	private static function write_data_version( int $version ): void {
		$updated = update_option( 'lel_data_version', $version, false );
		$stored  = (int) get_option( 'lel_data_version', 0 );
		if ( ! $updated || $stored !== $version ) {
			throw new \RuntimeException( sprintf( 'Migration version %d could not be durably confirmed (stored %d).', $version, $stored ) );
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
		$users = get_users(
			array(
				'meta_key'   => 'credential_verification_status',
				'meta_value' => 'verified',
				'fields'     => 'ids',
			)
		);
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
		$cursor       = (int) get_option( 'lel_migration_cursor_5', 0 );
		$legacy       = array(
			'fact_check_status'           => array( 'complete' ),
			'medical_review_status'       => array( 'complete' ),
			'testing_status'              => array( 'complete', 'approved' ),
			'affiliate_disclosure_status' => array( 'complete', 'approved' ),
			'editorial_approval_status'   => array( 'ready', 'published' ),
		);
		$total_marked = (int) get_option( 'lel_legacy_approvals_marked', 0 );
		while ( true ) {
			$posts = get_posts(
				array(
					'post_type'      => array( 'post', 'review' ),
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => self::BATCH_SIZE,
					'offset'         => $cursor,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
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
		if ( ! Audit_Log::exists() ) {
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
