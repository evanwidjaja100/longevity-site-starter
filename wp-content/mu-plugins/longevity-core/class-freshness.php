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
			$report['cycle_scanned'] = self::count_by_meta_query( array( 'post', 'review' ), array( array( 'key' => '_lel_freshness_last_scanned_at', 'value' => $cycle_started, 'compare' => '>=', 'type' => 'DATETIME' ) ), array( 'publish', 'draft', 'pending', 'future', 'private' ) );
			$report['due_total'] = self::count_by_meta_query( array( 'post', 'review' ), array( 'relation' => 'OR', array( 'key' => 'next_content_review_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ), array( 'key' => 'next_fact_check_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ), array( 'key' => 'next_medical_review_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ) ), array( 'publish', 'draft', 'pending', 'future', 'private' ) );
			$report['remaining_estimate'] = max( 0, $report['eligible_total'] - $report['cycle_scanned'] );
			$report['last_success_at'] = gmdate( DATE_W3C );
			$report['cycle_complete'] = $report['eligible_total'] <= $report['cycle_scanned'];
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
			echo '<tr><th scope="row">' . esc_html( ucwords( str_replace( '_', ' ', $label ) ) ) . '</th><td>' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Return non-sensitive operational counts for admin and CLI. */
	public static function status(): array {
		$last = get_option( 'lel_last_freshness_report', array() );
		$today = gmdate( 'Y-m-d' );
		$pub_status = array( 'publish', 'draft', 'pending', 'future', 'private' );
		return array(
			'last_freshness_run'        => is_array( $last ) ? ( $last['run_at'] ?? __( 'Never', 'longevity-core' ) ) : __( 'Never', 'longevity-core' ),
			'overdue_content_reviews'   => self::count_by_meta_query( array( 'post', 'review' ), array( 'key' => '_lel_freshness_status', 'value' => 'update_due' ) ),
			'overdue_fact_checks'       => self::count_by_meta_query( array( 'post', 'review' ), array( array( 'key' => 'next_fact_check_date', 'value' => $today, 'compare' => '<', 'type' => 'DATE' ) ) ),
			'overdue_medical_reviews'   => self::count_by_meta_query( array( 'post', 'review' ), array( array( 'key' => 'next_medical_review_date', 'value' => $today, 'compare' => '<', 'type' => 'DATE' ) ) ),
			'pending_corrections'       => self::count_by_meta_query( 'lel_correction', array( 'relation' => 'OR', array( 'key' => 'correction_status', 'compare' => 'NOT EXISTS' ), array( 'key' => 'correction_status', 'value' => 'complete', 'compare' => '!=' ) ) ),
			'unapproved_affiliates'     => self::count_by_meta_query( 'lel_affiliate', array( 'relation' => 'OR', array( 'key' => 'relationship_status', 'compare' => 'NOT EXISTS' ), array( 'key' => 'relationship_status', 'value' => 'active', 'compare' => '!=' ) ) ),
			'invalid_test_records'      => self::count_by_meta_query( 'lel_test_record', array( 'relation' => 'OR', array( 'key' => 'approval_status', 'compare' => 'NOT EXISTS' ), array( 'key' => 'approval_status', 'value' => 'approved', 'compare' => '!=' ) ) ),
			'repeated_emergency_overrides' => self::count_repeated_overrides(),
			'failed_freshness_jobs'     => get_option( 'lel_freshness_last_error', false ) ? 1 : 0,
			'last_batch_processed'      => is_array( $last ) ? (int) ( $last['processed'] ?? 0 ) : 0,
			'cycle_scanned'             => is_array( $last ) ? (int) ( $last['cycle_scanned'] ?? 0 ) : 0,
			'eligible_total'            => is_array( $last ) ? (int) ( $last['eligible_total'] ?? 0 ) : 0,
			'cycle_complete'            => is_array( $last ) ? (bool) ( $last['cycle_complete'] ?? false ) : false,
		);
	}

	/** Count records matching a meta query via direct SQL (avoids SQL_CALC_FOUND_ROWS). */
	private static function count_by_meta_query( $post_type, array $meta_query, $post_status = 'any' ): int {
		global $wpdb;
		$types = is_array( $post_type ) ? $post_type : array( $post_type );
		$types_in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$statuses = is_array( $post_status ) ? $post_status : array( $post_status );
		if ( in_array( 'any', $statuses, true ) ) {
			$where = 'p.post_type IN (' . $types_in . ')';
		} else {
			$status_in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where = 'p.post_type IN (' . $types_in . ') AND p.post_status IN (' . $status_in . ')';
		}
		$join = '';
		$params = array_merge( $types, $statuses );
		$relation = isset( $meta_query['relation'] ) && 'OR' === strtoupper( (string) $meta_query['relation'] ) ? ' OR ' : ' AND ';
		$conditions = array();
		foreach ( $meta_query as $clause ) {
			if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
				continue;
			}
			$alias = 'pm_' . md5( (string) $clause['key'] . (string) ($clause['value'] ?? '') );
			$join .= ' INNER JOIN ' . $wpdb->postmeta . ' ' . $alias . ' ON p.ID = ' . $alias . '.post_id';
			$cond = $alias . '.meta_key = %s';
			$params[] = $clause['key'];
			if ( isset( $clause['value'] ) ) {
				$compare = isset( $clause['compare'] ) ? (string) $clause['compare'] : '=';
				$cond .= ' AND ' . $alias . '.meta_value ' . $compare . ' %s';
				$params[] = (string) $clause['value'];
			}
			$conditions[] = $cond;
		}
		if ( ! empty( $conditions ) ) {
			$where .= ' AND (' . implode( $relation, $conditions ) . ')';
		}
		$sql = $wpdb->prepare( 'SELECT COUNT(DISTINCT p.ID) FROM ' . $wpdb->posts . ' p ' . $join . ' WHERE ' . $where, $params );
		return (int) $wpdb->get_var( $sql );
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
