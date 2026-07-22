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
	private const LOCK = 'lel_freshness_lock';

	/** Register cron and authenticated status hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'schedule' ), 30 );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'admin_menu', array( self::class, 'register_status_page' ) );
	}

	/** Schedule the daily bounded audit if it is not already scheduled. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/** Run a bounded, locked scan and return a non-sensitive report. */
	public static function run(): array {
		$existing_lock = get_transient( self::LOCK );
		if ( $existing_lock ) {
			$lock_age = is_numeric( $existing_lock ) ? max( 0, time() - (int) $existing_lock ) : null;
			return array( 'status' => 'locked', 'processed' => 0, 'due' => 0, 'lock_age' => $lock_age, 'last_error_code' => 'freshness_locked' );
		}
		set_transient( self::LOCK, (string) time(), 15 * MINUTE_IN_SECONDS );
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
					update_post_meta( (int) $post_id, '_lel_freshness_due_fields', array_values( $due_fields ) );
					Publication_Gates::log_event( (int) $post_id, 'freshness_update_due', array( 'checks' => count( $due_fields ) ) );
				} else {
					delete_post_meta( (int) $post_id, '_lel_freshness_status' );
					delete_post_meta( (int) $post_id, '_lel_freshness_due_fields' );
				}
			}
			$report['eligible_total'] = Freshness_Repository::eligible_total();
			$report['cycle_started_at'] = $cycle_started;
			$report['last_cycle_started_at'] = $cycle_started;
			$report['cycle_scanned'] = self::count_scanned_since( $cycle_started );
			$report['processed_in_cycle'] = $report['cycle_scanned'];
			$report['due_total'] = self::count_any_due( $today );
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
			update_option( 'lel_last_freshness_report', $report, false );
			delete_option( 'lel_freshness_last_error' );
		} catch ( \Throwable $error ) {
			$report['status'] = 'failed';
			$error_code = sanitize_key( ( new \ReflectionClass( $error ) )->getShortName() );
			$report['last_error_code'] = $error_code;
			Audit_Log::record( 'freshness_cycle_failed', 'system', 0, array( 'error_class' => get_class( $error ), 'error_code' => $error_code ), 0, 'cron' );
			update_option( 'lel_freshness_last_error', array( 'time' => gmdate( DATE_W3C ), 'code' => $error_code ), false );
			if ( function_exists( 'error_log' ) ) {
				error_log( 'Longevity Core freshness job failed: ' . $error->getMessage() );
			}
		} finally {
			delete_transient( self::LOCK );
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
		return array(
			'last_freshness_run'        => is_array( $last ) ? ( $last['run_at'] ?? __( 'Never', 'longevity-core' ) ) : __( 'Never', 'longevity-core' ),
			'overdue_content_reviews'   => self::count_meta( array( 'post', 'review' ), '_lel_freshness_status', 'update_due' ),
			'overdue_fact_checks'       => self::count_due_field( 'next_fact_check_date' ),
			'overdue_medical_reviews'   => self::count_due_field( 'next_medical_review_date' ),
			'pending_corrections'       => self::count_not_meta( 'lel_correction', 'correction_status', 'complete' ),
			'unapproved_affiliates'     => self::count_not_meta( 'lel_affiliate', 'relationship_status', 'active' ),
			'invalid_test_records'      => self::count_not_meta( 'lel_test_record', 'approval_status', 'approved' ),
			'repeated_emergency_overrides' => self::count_repeated_overrides(),
			'failed_freshness_jobs'     => get_option( 'lel_freshness_last_error', false ) ? 1 : 0,
			'last_batch_processed'      => is_array( $last ) ? (int) ( $last['processed'] ?? 0 ) : 0,
			'cycle_scanned'             => is_array( $last ) ? (int) ( $last['cycle_scanned'] ?? 0 ) : 0,
			'eligible_total'            => is_array( $last ) ? (int) ( $last['eligible_total'] ?? 0 ) : 0,
			'cycle_complete'            => is_array( $last ) ? (bool) ( $last['cycle_complete'] ?? false ) : false,
		);
	}

	/** Count records with an exact meta value. */
	private static function count_meta( $post_type, string $key, string $value ): int {
		$query = new \WP_Query( array( 'post_type' => $post_type, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false, 'meta_key' => $key, 'meta_value' => $value ) );
		return (int) $query->found_posts;
	}

	/** Count records whose state is absent or not equal to the approved value. */
	private static function count_not_meta( string $post_type, string $key, string $value ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
					array( 'key' => $key, 'value' => $value, 'compare' => '!=' ),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/** Count overdue items for a specific lifecycle date in a bounded query. */
	private static function count_due_field( string $field ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'review' ),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'meta_query'     => array(
					array( 'key' => $field, 'value' => gmdate( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/** Count unique records with at least one lifecycle date due today or earlier. */
	private static function count_any_due( string $today ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'review' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => 'next_content_review_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ),
					array( 'key' => 'next_fact_check_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ),
					array( 'key' => 'next_medical_review_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ),
				),
			)
		);
		return (int) $query->found_posts;
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

	/** Count records scanned since the current cycle began. */
	private static function count_scanned_since( string $cycle_started ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'review' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'meta_query'     => array( array( 'key' => '_lel_freshness_last_scanned_at', 'value' => $cycle_started, 'compare' => '>=', 'type' => 'DATETIME' ) ),
			)
		);
		return (int) $query->found_posts;
	}
}
