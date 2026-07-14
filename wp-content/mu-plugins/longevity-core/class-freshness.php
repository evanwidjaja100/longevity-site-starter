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
		if ( get_transient( self::LOCK ) ) {
			return array( 'status' => 'locked', 'processed' => 0, 'due' => 0 );
		}
		set_transient( self::LOCK, '1', 15 * MINUTE_IN_SECONDS );
		$report = array( 'status' => 'ok', 'processed' => 0, 'due' => 0, 'run_at' => gmdate( DATE_W3C ) );
		try {
			$batch = min( 250, max( 10, (int) get_option( 'lel_freshness_batch_size', 100 ) ) );
			$posts = get_posts(
				array(
					'post_type'              => array( 'post', 'review' ),
					'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page'         => $batch,
					'fields'                 => 'ids',
					'orderby'                => array( 'modified' => 'ASC', 'ID' => 'ASC' ),
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			$today = gmdate( 'Y-m-d' );
			foreach ( $posts as $post_id ) {
				++$report['processed'];
				$due_fields = array();
				foreach ( array( 'next_content_review_date', 'next_fact_check_date', 'next_medical_review_date' ) as $field ) {
					$date = (string) get_post_meta( (int) $post_id, $field, true );
					if ( '' !== $date && $date < $today ) {
						$due_fields[] = $field;
					}
				}
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
			update_option( 'lel_last_freshness_report', $report, false );
			delete_option( 'lel_freshness_last_error' );
		} catch ( \Throwable $error ) {
			$report['status'] = 'failed';
			update_option( 'lel_freshness_last_error', array( 'time' => gmdate( DATE_W3C ) ), false );
			if ( function_exists( 'error_log' ) ) {
				error_log( 'Longevity Core freshness job failed.' );
			}
		} finally {
			delete_transient( self::LOCK );
		}
		return $report;
	}

	/** Register a capability-protected operational status page. */
	public static function register_status_page(): void {
		add_management_page( __( 'Longevity operational status', 'longevity-core' ), __( 'Longevity status', 'longevity-core' ), 'approve_publication', 'lel-operational-status', array( self::class, 'render_status_page' ) );
	}

	/** Render bounded operational issue counts without exposing private records. */
	public static function render_status_page(): void {
		if ( ! current_user_can( 'approve_publication' ) ) {
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

	/** Count posts with two or more recorded emergency publication overrides. */
	private static function count_repeated_overrides(): int {
		$posts = get_posts( array( 'post_type' => array( 'post', 'review' ), 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 250, 'meta_key' => '_longevity_audit_log', 'orderby' => 'ID', 'order' => 'DESC' ) );
		$count = 0;
		foreach ( $posts as $post_id ) {
			$events = get_post_meta( (int) $post_id, '_longevity_audit_log', true );
			if ( ! is_array( $events ) ) {
				continue;
			}
			$overrides = array_filter( $events, static fn( $event ) => is_array( $event ) && 'publication_override_used' === ( $event['event'] ?? '' ) );
			if ( count( $overrides ) >= 2 ) {
				++$count;
			}
		}
		return $count;
	}
}
