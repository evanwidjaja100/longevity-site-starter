<?php
/**
 * Bounded content-freshness automation and operational status.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Identifies due records without rewriting, approving, or publishing content. */
final class Freshness {
	private const HOOK = 'lel_daily_freshness';
	private const WORKERS = array(
		'freshness'    => array( 'hook' => self::HOOK, 'max_age' => 172800 ),
		'invalidation' => array( 'hook' => 'lel_invalidation_queue_process', 'max_age' => 300 ),
		'outbox'       => array( 'hook' => 'lel_notification_outbox_send', 'max_age' => 7200 ),
		'retention'    => array( 'hook' => 'lel_contact_retention_cleanup', 'max_age' => 172800 ),
	);

	/** Only these comparison operators are accepted by the operational-count builder. */
	private const META_COMPARE_OPERATORS = array( '=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' );

	/** Map meta_query value types to a CAST target; '' means compare the raw meta_value. */
	private const META_CAST_TYPES = array(
		'CHAR'     => '',
		'BINARY'   => 'BINARY',
		'DATE'     => 'DATE',
		'DATETIME' => 'DATETIME',
		'TIME'     => 'TIME',
		'NUMERIC'  => 'SIGNED',
		'SIGNED'   => 'SIGNED',
		'UNSIGNED' => 'UNSIGNED',
		'DECIMAL'  => 'DECIMAL(10,2)',
	);

	/** Register cron and authenticated status hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'schedule' ), 30 );
		add_action( self::HOOK, array( self::class, 'run_scheduled' ) );
		foreach ( self::WORKERS as $worker => $config ) {
			if ( 'freshness' !== $worker ) {
				add_action( $config['hook'], static fn() => self::record_worker_heartbeat( $worker ), PHP_INT_MAX );
			}
		}
		add_action( 'admin_menu', array( self::class, 'register_status_page' ) );
	}

	/** Cron adapter that records success only after a completed freshness run. */
	public static function run_scheduled(): void {
		self::run();
	}

	/** Persist a namespaced worker heartbeat. */
	public static function record_worker_heartbeat( string $worker ): void {
		if ( isset( self::WORKERS[ $worker ] ) ) {
			update_option( 'lel_worker_heartbeat_' . $worker, gmdate( DATE_W3C ), false );
		}
	}

	/** Individual schedule/heartbeat states consumed by readiness and promotion acceptance. */
	public static function worker_statuses(): array {
		$statuses = array();
		foreach ( self::WORKERS as $worker => $config ) {
			$scheduled = false !== wp_next_scheduled( $config['hook'] );
			$heartbeat = (string) get_option( 'lel_worker_heartbeat_' . $worker, '' );
			$timestamp = '' === $heartbeat ? false : strtotime( $heartbeat );
			$current   = false !== $timestamp && $timestamp >= time() - $config['max_age'];
			$statuses[ $worker ] = array(
				'status'       => $scheduled && $current ? 'ok' : 'blocked',
				'hook'         => $config['hook'],
				'scheduled'    => $scheduled,
				'heartbeat_at' => $heartbeat,
			);
		}
		return $statuses;
	}

	/** Schedule the daily bounded audit if it is not already scheduled. */
	public static function schedule(): void {
		if ( ! Migrations::wordpress_ready() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/** Run a bounded, locked scan and return a non-sensitive report. */
	public static function run(): array {
		$lock_name   = Advisory_Lock::namespaced_name( 'freshness_cycle' );
		$lock_result = Advisory_Lock::acquire( $lock_name, 0 );
		if ( Advisory_Lock::CONTENDED === $lock_result ) {
			return array( 'status' => 'locked', 'processed' => 0, 'due' => 0, 'lock_age' => null, 'last_error_code' => 'freshness_locked' );
		}
		if ( Advisory_Lock::ACQUIRED !== $lock_result ) {
			// Fail closed: never run an unlocked cycle on lock errors.
			update_option( 'lel_freshness_lock_errors', (int) get_option( 'lel_freshness_lock_errors', 0 ) + 1, false );
			Logger::warning( 'freshness_lock_error', array( 'lock_result' => $lock_result ) );
			return array( 'status' => 'lock_error', 'processed' => 0, 'due' => 0, 'lock_age' => null, 'last_error_code' => 'freshness_lock_' . $lock_result );
		}
		$run_at = gmdate( DATE_W3C );
		$report = array( 'status' => 'ok', 'processed' => 0, 'due' => 0, 'run_at' => $run_at, 'last_run_at' => $run_at, 'lock_age' => 0, 'last_error_code' => '' );
		try {
			$batch = min( 250, max( 10, (int) get_option( 'lel_freshness_batch_size', 100 ) ) );
			$posts = Freshness_Repository::next_batch( $batch );
			$today = Date_Validator::today();
			$cycle_started = (string) get_option( 'lel_freshness_cycle_started_at', '' );
			if ( '' === $cycle_started ) {
				$cycle_started = gmdate( 'Y-m-d H:i:s' );
				update_option( 'lel_freshness_cycle_started_at', $cycle_started, false );
				update_option( 'lel_freshness_cycle_id', function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', $cycle_started ), false );
			}
			foreach ( $posts as $post_id ) {
				++$report['processed'];
				$due_fields = array();
				foreach ( array( 'next_content_review_date', 'next_fact_check_date', 'next_medical_review_date' ) as $field ) {
					$date = (string) get_post_meta( (int) $post_id, $field, true );
					if ( Date_Validator::is_valid( $date ) && Date_Validator::compare( $date, $today ) <= 0 ) {
						$due_fields[] = $field;
					}
				}
				update_post_meta( (int) $post_id, '_lel_freshness_last_scanned_at', gmdate( 'Y-m-d H:i:s' ) );
				if ( $due_fields ) {
					++$report['due'];
					update_post_meta( (int) $post_id, '_lel_freshness_status', 'update_due' );
					update_post_meta( (int) $post_id, '_lel_freshness_due_fields', $due_fields );
					Publication_Gates::log_event( (int) $post_id, 'freshness_update_due', array( 'checks' => count( $due_fields ) ) );
				} else {
					delete_post_meta( (int) $post_id, '_lel_freshness_status' );
					delete_post_meta( (int) $post_id, '_lel_freshness_due_fields' );
				}
			}
			$report['eligible_total'] = Freshness_Repository::eligible_total();
			$report['cycle_started_at'] = $cycle_started;
			$cycle_scanned = self::count_by_meta_query( array( 'post', 'review' ), array( array( 'key' => '_lel_freshness_last_scanned_at', 'value' => $cycle_started, 'compare' => '>=', 'type' => 'DATETIME' ) ), array( 'publish', 'draft', 'pending', 'future', 'private' ) );
			$due_total     = self::count_by_meta_query( array( 'post', 'review' ), array( 'relation' => 'OR', array( 'key' => 'next_content_review_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ), array( 'key' => 'next_fact_check_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ), array( 'key' => 'next_medical_review_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ) ), array( 'publish', 'draft', 'pending', 'future', 'private' ) );
			$report['cycle_scanned'] = $cycle_scanned;
			$report['due_total']     = $due_total;
			$report['counts_available'] = ( null !== $cycle_scanned ) && ( null !== $due_total );
			if ( null === $cycle_scanned ) {
				// An unreadable scan count must never be treated as zero progress or completion.
				$report['remaining_estimate'] = null;
				$report['cycle_complete']     = false;
			} else {
				$report['remaining_estimate'] = max( 0, $report['eligible_total'] - $cycle_scanned );
				$report['cycle_complete']     = $report['eligible_total'] <= $cycle_scanned;
			}
			$report['last_success_at'] = gmdate( DATE_W3C );
			if ( $report['cycle_complete'] ) {
				$report['cycle_completed_at'] = gmdate( DATE_W3C );
				$report['last_cycle_completed_at'] = $report['cycle_completed_at'];
				update_option( 'lel_freshness_last_cycle_completed_at', $report['cycle_completed_at'], false );
				delete_option( 'lel_freshness_cycle_started_at' );
			} else {
				$report['last_cycle_completed_at'] = (string) get_option( 'lel_freshness_last_cycle_completed_at', '' );
				$next = wp_next_scheduled( self::HOOK );
				if ( ! $next || $next > time() + ( 2 * HOUR_IN_SECONDS ) ) {
					wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK );
				}
			}
			update_option( 'lel_cron_heartbeat_at', gmdate( DATE_W3C ), false );
			self::record_worker_heartbeat( 'freshness' );
			update_option( 'lel_last_freshness_report', $report, false );
			delete_option( 'lel_freshness_last_error' );
		} catch ( \Throwable $error ) {
			$report['status'] = 'failed';
			$error_code = sanitize_key( ( new \ReflectionClass( $error ) )->getShortName() );
			$report['last_error_code'] = $error_code;
			Audit_Log::record( 'freshness_cycle_failed', 'system', 0, array( 'error_class' => get_class( $error ), 'error_code' => $error_code ), 0, 'cron' );
			update_option( 'lel_freshness_last_error', array( 'time' => gmdate( DATE_W3C ), 'code' => $error_code ), false );
			Logger::error( 'freshness_cycle_failed', array( 'error_code' => $error_code, 'message' => $error->getMessage() ) );
		} finally {
			Advisory_Lock::release( $lock_name );
		}
		return $report;
	}

	/** Register a capability-protected operational status page. */
	public static function register_status_page(): void {
		add_management_page( __( 'Longevity operational status', 'longevity-core' ), __( 'Longevity status', 'longevity-core' ), 'view_operational_readiness', 'lel-operational-status', array( self::class, 'render_status_page' ) );
	}

	/** Render bounded operational issue counts without exposing private records. */
	public static function render_status_page(): void {
		if ( ! current_user_can( 'view_operational_readiness' ) ) {
			wp_die( esc_html__( 'You are not allowed to view operational status.', 'longevity-core' ) );
		}
		$report = self::status();
		echo '<div class="wrap"><h1>' . esc_html__( 'Longevity operational status', 'longevity-core' ) . '</h1><p>' . esc_html__( 'Counts are operational signals. Inspect and resolve records through their protected editorial screens.', 'longevity-core' ) . '</p><table class="widefat striped"><tbody>';
		foreach ( $report as $label => $value ) {
			if ( null === $value ) {
				$display = __( 'unavailable', 'longevity-core' );
			} elseif ( is_scalar( $value ) ) {
				$display = (string) $value;
			} else {
				$display = (string) wp_json_encode( $value );
			}
			echo '<tr><th scope="row">' . esc_html( ucwords( str_replace( '_', ' ', $label ) ) ) . '</th><td>' . esc_html( $display ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Return non-sensitive operational counts for admin and CLI. */
	public static function status(): array {
		$last  = get_option( 'lel_last_freshness_report', array() );
		$today = gmdate( 'Y-m-d' );
		$counts = array(
			'overdue_content_reviews' => self::count_by_meta_query( array( 'post', 'review' ), array( 'key' => '_lel_freshness_status', 'value' => 'update_due' ) ),
			'overdue_fact_checks'     => self::count_by_meta_query( array( 'post', 'review' ), array( array( 'key' => 'next_fact_check_date', 'value' => $today, 'compare' => '<', 'type' => 'DATE' ) ) ),
			'overdue_medical_reviews' => self::count_by_meta_query( array( 'post', 'review' ), array( array( 'key' => 'next_medical_review_date', 'value' => $today, 'compare' => '<', 'type' => 'DATE' ) ) ),
			'pending_corrections'     => self::count_by_meta_query( 'lel_correction', array( 'relation' => 'OR', array( 'key' => 'correction_status', 'compare' => 'NOT EXISTS' ), array( 'key' => 'correction_status', 'value' => 'complete', 'compare' => '!=' ) ) ),
			'unapproved_affiliates'   => self::count_by_meta_query( 'lel_affiliate', array( 'relation' => 'OR', array( 'key' => 'relationship_status', 'compare' => 'NOT EXISTS' ), array( 'key' => 'relationship_status', 'value' => 'active', 'compare' => '!=' ) ) ),
			'invalid_test_records'    => self::count_by_meta_query( 'lel_test_record', array( 'relation' => 'OR', array( 'key' => 'approval_status', 'compare' => 'NOT EXISTS' ), array( 'key' => 'approval_status', 'value' => 'approved', 'compare' => '!=' ) ) ),
		);
		$unavailable = array_keys( array_filter( $counts, static fn( $value ): bool => null === $value ) );
		return array(
			'last_freshness_run'           => is_array( $last ) ? ( $last['run_at'] ?? __( 'Never', 'longevity-core' ) ) : __( 'Never', 'longevity-core' ),
			'overdue_content_reviews'      => $counts['overdue_content_reviews'],
			'overdue_fact_checks'          => $counts['overdue_fact_checks'],
			'overdue_medical_reviews'      => $counts['overdue_medical_reviews'],
			'pending_corrections'          => $counts['pending_corrections'],
			'unapproved_affiliates'        => $counts['unapproved_affiliates'],
			'invalid_test_records'         => $counts['invalid_test_records'],
			'operational_counts_available' => array() === $unavailable,
			'unavailable_counts'           => $unavailable,
			'repeated_emergency_overrides' => self::count_repeated_overrides(),
			'failed_freshness_jobs'     => get_option( 'lel_freshness_last_error', false ) ? 1 : 0,
			'last_batch_processed'      => is_array( $last ) ? (int) ( $last['processed'] ?? 0 ) : 0,
			'cycle_scanned'             => is_array( $last ) ? (int) ( $last['cycle_scanned'] ?? 0 ) : 0,
			'eligible_total'            => is_array( $last ) ? (int) ( $last['eligible_total'] ?? 0 ) : 0,
			'cycle_complete'            => is_array( $last ) ? (bool) ( $last['cycle_complete'] ?? false ) : false,
		);
	}

	/**
	 * Build a COUNT(DISTINCT p.ID) query and its ordered parameters for a bounded meta query.
	 *
	 * Pure and side-effect free so structural correctness (join shape, operator
	 * allowlisting, placeholder/parameter parity and ordering) can be unit tested
	 * without a database. Returns a null `sql` with a non-empty `error` when the
	 * meta query cannot be represented safely.
	 *
	 * @param string|array<int, string> $post_type   One or more post types.
	 * @param array<mixed>              $meta_query  A flat clause or a relation-grouped set of clauses.
	 * @param string|array<int, string> $post_status 'any' (no status filter) or explicit statuses.
	 * @return array{sql: ?string, params: array<int, string>, error: string}
	 */
	public static function build_meta_count_query( $post_type, array $meta_query, $post_status = 'any' ): array {
		global $wpdb;
		$posts    = isset( $wpdb ) && isset( $wpdb->posts ) ? (string) $wpdb->posts : 'wp_posts';
		$postmeta = isset( $wpdb ) && isset( $wpdb->postmeta ) ? (string) $wpdb->postmeta : 'wp_postmeta';

		$types = array_values( array_filter( array_map( 'strval', is_array( $post_type ) ? $post_type : array( $post_type ) ), static fn( string $type ): bool => '' !== $type ) );
		if ( array() === $types ) {
			return array( 'sql' => null, 'params' => array(), 'error' => 'empty_post_type' );
		}

		$statuses   = is_array( $post_status ) ? array_values( array_map( 'strval', $post_status ) ) : array( (string) $post_status );
		$any_status = array() === $statuses || in_array( 'any', $statuses, true );

		// A flat clause (top-level 'key') is a single AND condition; otherwise honour the relation.
		if ( isset( $meta_query['key'] ) ) {
			$relation = ' AND ';
			$clauses  = array( $meta_query );
		} else {
			$relation = isset( $meta_query['relation'] ) && 'OR' === strtoupper( (string) $meta_query['relation'] ) ? ' OR ' : ' AND ';
			$clauses  = array();
			foreach ( $meta_query as $key => $clause ) {
				if ( 'relation' !== $key && is_array( $clause ) && isset( $clause['key'] ) ) {
					$clauses[] = $clause;
				}
			}
		}

		// JOIN clauses precede the WHERE clause, so their bound meta_key params must come first.
		$join_sql    = '';
		$join_params = array();
		$conditions  = array();
		$cond_params = array();
		$index       = 0;
		foreach ( $clauses as $clause ) {
			$alias    = 'pm' . $index;
			++$index;
			$meta_key = (string) $clause['key'];
			$compare  = isset( $clause['compare'] ) ? strtoupper( trim( (string) $clause['compare'] ) ) : ( isset( $clause['value'] ) ? '=' : 'EXISTS' );
			if ( ! in_array( $compare, self::META_COMPARE_OPERATORS, true ) ) {
				return array( 'sql' => null, 'params' => array(), 'error' => 'unsupported_operator:' . $compare );
			}

			if ( 'NOT EXISTS' === $compare ) {
				$join_sql     .= " LEFT JOIN {$postmeta} {$alias} ON p.ID = {$alias}.post_id AND {$alias}.meta_key = %s";
				$join_params[] = $meta_key;
				$conditions[]  = "{$alias}.post_id IS NULL";
				continue;
			}

			// Every remaining operator requires the row to exist: INNER JOIN on the key.
			$join_sql     .= " INNER JOIN {$postmeta} {$alias} ON p.ID = {$alias}.post_id AND {$alias}.meta_key = %s";
			$join_params[] = $meta_key;

			if ( 'EXISTS' === $compare ) {
				continue; // Presence is already guaranteed by the INNER JOIN.
			}

			$type   = isset( $clause['type'] ) ? strtoupper( trim( (string) $clause['type'] ) ) : '';
			$cast   = self::META_CAST_TYPES[ $type ] ?? '';
			$column = '' === $cast ? "{$alias}.meta_value" : "CAST({$alias}.meta_value AS {$cast})";

			if ( 'IN' === $compare || 'NOT IN' === $compare ) {
				$values = array_values( array_map( 'strval', (array) ( $clause['value'] ?? array() ) ) );
				if ( array() === $values ) {
					return array( 'sql' => null, 'params' => array(), 'error' => 'empty_in_set' );
				}
				$conditions[] = "{$column} {$compare} (" . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')';
				foreach ( $values as $value ) {
					$cond_params[] = $value;
				}
				continue;
			}

			if ( ! isset( $clause['value'] ) ) {
				return array( 'sql' => null, 'params' => array(), 'error' => 'missing_value' );
			}
			$conditions[]  = "{$column} {$compare} %s";
			$cond_params[] = (string) $clause['value'];
		}

		$where_parts  = array( 'p.post_type IN (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')' );
		$where_params = $types;
		if ( ! $any_status ) {
			$where_parts[] = 'p.post_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$where_params  = array_merge( $where_params, $statuses );
		}
		if ( array() !== $conditions ) {
			$where_parts[] = '(' . implode( $relation, $conditions ) . ')';
		}

		$sql = "SELECT COUNT(DISTINCT p.ID) FROM {$posts} p{$join_sql} WHERE " . implode( ' AND ', $where_parts );

		// Placeholder order in SQL: JOIN meta_key(s), post_type(s), post_status(es), then condition values.
		return array(
			'sql'    => $sql,
			'params' => array_merge( $join_params, $where_params, $cond_params ),
			'error'  => '',
		);
	}

	/**
	 * Execute a bounded meta count, failing closed to null on invalid input or a database error.
	 * An unreadable operational count must never be reported as zero.
	 *
	 * @param string|array<int, string> $post_type   One or more post types.
	 * @param array<mixed>              $meta_query  A flat clause or a relation-grouped set of clauses.
	 * @param string|array<int, string> $post_status 'any' or explicit statuses.
	 */
	private static function count_by_meta_query( $post_type, array $meta_query, $post_status = 'any' ): ?int {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}
		$built = self::build_meta_count_query( $post_type, $meta_query, $post_status );
		if ( null === $built['sql'] ) {
			Logger::error( 'freshness_count_query_invalid', array( 'error' => (string) $built['error'] ) );
			return null;
		}
		$wpdb->last_error = '';
		$sql      = array() === $built['params'] ? $built['sql'] : $wpdb->prepare( $built['sql'], $built['params'] );
		$result   = $wpdb->get_var( $sql );
		$db_error = trim( (string) ( $wpdb->last_error ?? '' ) );
		if ( null === $result || '' !== $db_error ) {
			Logger::error( 'freshness_count_query_failed', array( 'reason' => '' !== $db_error ? 'db_error' : 'null_result' ) );
			return null;
		}
		return (int) $result;
	}

	/** Count posts with two or more append-only emergency override events. */
	private static function count_repeated_overrides(): int {
		global $wpdb;
		if ( ! Audit_Log::exists() || ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0;
		}
		$table = Audit_Log::table_name();
		$sql   = "SELECT COUNT(*) FROM (SELECT object_id FROM {$table} WHERE event_type = 'publication_override_used' AND object_type = 'post' GROUP BY object_id HAVING COUNT(*) >= 2) AS repeated";
		return (int) $wpdb->get_var( $sql );
	}
}
