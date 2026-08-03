<?php
/**
 * Invalidation queue integrity tests.
 *
 * @package LongevityCore
 */

use Longevity\Core\Audit_Log;
use Longevity\Core\Approval_Service;
use Longevity\Core\Invalidation_Queue;
use PHPUnit\Framework\TestCase;

final class InvalidationQueueTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_invalidation_queue', array() );
		$GLOBALS['wpdb']->lel_query_log = array();
		$GLOBALS['lel_test_options']    = array();
		$GLOBALS['lel_test_meta']       = array();
		$GLOBALS['lel_test_scheduled']  = array();
		unset( $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_fail_queue_transition'], $GLOBALS['lel_test_missing_schema_column'] );
		Audit_Log::set_test_mode( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_fail_queue_transition'], $GLOBALS['lel_test_missing_schema_column'] );
		Audit_Log::set_test_mode( true );
	}

	private static function log(): array {
		return $GLOBALS['wpdb']->lel_query_log;
	}

	private static function rows(): array {
		return $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_invalidation_queue' );
	}

	public function test_enqueue_is_single_statement_without_select_dedup(): void {
		Invalidation_Queue::enqueue( array( 5 ), 'source_changed', 1 );

		$inserts = array_values( array_filter( self::log(), static fn( string $sql ): bool => (bool) preg_match( '/^\s*INSERT INTO wp_lel_invalidation_queue/i', $sql ) ) );
		self::assertCount( 1, $inserts, 'Enqueue must issue exactly one INSERT.' );
		self::assertStringContainsStringIgnoringCase( 'ON DUPLICATE KEY UPDATE', $inserts[0], 'Dedup must be enforced by the unique key, not application logic.' );

		foreach ( self::log() as $sql ) {
			self::assertFalse(
				(bool) preg_match( '/^\s*SELECT id FROM wp_lel_invalidation_queue/i', $sql ),
				'Enqueue must not use a racy SELECT-then-INSERT dedup probe: ' . $sql
			);
		}
	}

	public function test_duplicate_enqueue_yields_exactly_one_open_row(): void {
		Invalidation_Queue::enqueue( array( 5 ), 'source_changed', 1 );
		Invalidation_Queue::enqueue( array( 5 ), 'claim_changed', 2 );

		$open = array_values( array_filter( self::rows(), static fn( array $row ): bool => ! empty( $row['open_marker'] ) ) );
		self::assertCount( 1, $open, 'Duplicate enqueue for the same parent must collapse into one open job.' );
	}

	public function test_enqueue_write_failure_blocks_readiness(): void {
		$GLOBALS['lel_test_fail_queue_insert'] = true;
		Invalidation_Queue::enqueue( array( 5 ), 'source_changed', 1 );
		unset( $GLOBALS['lel_test_fail_queue_insert'] );
		self::assertSame( 1, Invalidation_Queue::enqueue_failure_count() );
		self::assertSame( 'blocked', \Longevity\Core\System_Readiness::report()['checks']['invalidation_queue']['status'] );
	}

	public function test_claim_is_atomic_update_with_lease(): void {
		Invalidation_Queue::enqueue( array( 7 ), 'source_changed', 1 );
		$GLOBALS['wpdb']->lel_query_log = array();

		Invalidation_Queue::process_batch();

		$claims = array_values(
			array_filter(
				self::log(),
				static fn( string $sql ): bool => (bool) preg_match( '/^\s*UPDATE wp_lel_invalidation_queue/i', $sql )
					&& false !== strpos( $sql, "status = 'processing'" )
					&& false !== strpos( $sql, 'lease_owner' )
			)
		);
		self::assertNotEmpty( $claims, 'Worker must claim jobs with an atomic UPDATE that sets a lease.' );
		self::assertMatchesRegularExpression( '/ORDER BY id ASC LIMIT 1/i', $claims[0] );

		foreach ( self::log() as $sql ) {
			self::assertFalse(
				(bool) preg_match( "/^\s*SELECT \* FROM wp_lel_invalidation_queue WHERE status = 'pending'/i", $sql ),
				'Worker must not use a racy read-then-update claim: ' . $sql
			);
		}
	}

	public function test_worker_never_runs_schema_ddl(): void {
		Invalidation_Queue::enqueue( array( 8 ), 'source_changed', 1 );
		$GLOBALS['wpdb']->lel_query_log = array();

		Invalidation_Queue::process_batch();

		self::assertSame( array(), array_values( array_filter( self::log(), static fn( string $sql ): bool => (bool) preg_match( '/^\s*(ALTER|CREATE|DROP)\b/i', $sql ) ) ) );
	}

	public function test_partial_queue_schema_falls_back_without_request_time_ddl(): void {
		$GLOBALS['lel_test_missing_schema_column'] = 'audit_event_id';
		$GLOBALS['wpdb']->lel_query_log = array();

		Invalidation_Queue::enqueue( array( 18 ), 'partial_schema', 1 );

		self::assertSame( array(), self::rows(), 'An incomplete queue must not receive a row.' );
		self::assertSame( array(), array_values( array_filter( self::log(), static fn( string $sql ): bool => (bool) preg_match( '/^\s*ALTER\b/i', $sql ) ) ) );
		self::assertSame( array( 'approval_invalidation_intent', 'approval_invalidated' ), array_column( Audit_Log::test_events(), 'event_type' ) );
	}

	public function test_missing_queue_runs_invalidation_synchronously(): void {
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_approval_snapshots',
			array( array( 'id' => 1, 'post_id' => 19, 'approval_type' => 'editorial', 'approval_status' => 'approved', 'invalidated_at' => null ) )
		);
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_invalidation_queue' );

		Invalidation_Queue::enqueue( array( 19 ), 'queue_missing', 1 );

		self::assertNotEmpty( $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_approval_snapshots' )[0]['invalidated_at'] );
	}

	public function test_missing_queue_cannot_hide_failed_synchronous_invalidation(): void {
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_invalidation_queue' );
		$GLOBALS['lel_test_get_lock_result'] = '0';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'synchronous invalidation failed' );
		Invalidation_Queue::enqueue( array( 20 ), 'queue_missing', 1 );
	}

	public function test_invalidation_audits_mandatory_intent_before_mutation_outcome(): void {
		Audit_Log::reset_test_events();

		Approval_Service::invalidate_direct( 21, 'content_changed', 1 );

		self::assertSame( array( 'approval_invalidation_intent', 'approval_invalidated' ), array_column( Audit_Log::test_events(), 'event_type' ) );
		self::assertTrue( Audit_Log::test_events()[0]['mandatory'] );
	}

	public function test_failed_intent_prevents_state_mutation(): void {
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_approval_snapshots',
			array( array( 'id' => 1, 'post_id' => 22, 'approval_type' => 'editorial', 'approval_status' => 'approved', 'invalidated_at' => null ) )
		);
		Audit_Log::set_test_fail_events( array( 'approval_invalidation_intent' ) );

		try {
			Approval_Service::invalidate_direct( 22, 'content_changed', 1 );
			self::fail( 'A missing mandatory intent must abort invalidation.' );
		} catch ( RuntimeException $error ) {
			self::assertNull( $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_approval_snapshots' )[0]['invalidated_at'] );
		}
	}

	public function test_failed_completion_leaves_reconciliation_job(): void {
		Audit_Log::set_test_fail_events( array( 'approval_invalidated' ) );

		try {
			Approval_Service::invalidate_direct( 23, 'content_changed', 1 );
			self::fail( 'A failed mandatory completion audit must surface.' );
		} catch ( RuntimeException $error ) {
			$rows = self::rows();
			self::assertCount( 1, $rows );
			self::assertSame( 'pending', $rows[0]['status'] );
			self::assertStringContainsString( 'reconcile_intent:1:content_changed', $rows[0]['reason'] );
		}
	}

	public function test_process_batch_completes_job_and_clears_marker_and_lease(): void {
		Invalidation_Queue::enqueue( array( 7 ), 'source_changed', 1 );

		$processed = Invalidation_Queue::process_batch();

		self::assertSame( 1, $processed );
		$rows = self::rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'completed', $rows[0]['status'] );
		self::assertNull( $rows[0]['open_marker'], 'Terminal rows must release the unique open slot.' );
		self::assertEmpty( $rows[0]['lease_owner'] );
		self::assertNotEmpty( $rows[0]['processed_at'] );
		self::assertGreaterThan( 0, (int) $rows[0]['audit_event_id'], 'Completion must retain the durable audit event ID.' );
	}

	public function test_expired_lease_is_reclaimed(): void {
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_invalidation_queue',
			array(
				array(
					'id'               => 1,
					'parent_post_id'   => 9,
					'reason'           => 'stuck',
					'actor_id'         => 0,
					'status'           => 'processing',
					'retry_count'      => 0,
					'open_marker'      => 1,
					'lease_owner'      => 'dead-worker',
					'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
					'created_at'       => gmdate( 'Y-m-d H:i:s', time() - 900 ),
					'processed_at'     => null,
					'last_error'       => null,
				),
			)
		);

		$processed = Invalidation_Queue::process_batch();

		self::assertSame( 1, $processed, 'A job with an expired lease must be reclaimable by another worker.' );
		self::assertSame( 'completed', self::rows()[0]['status'] );
	}

	public function test_exhausted_retries_dead_letter_job(): void {
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_invalidation_queue',
			array(
				array(
					'id'               => 1,
					'parent_post_id'   => 11,
					'reason'           => 'flaky',
					'actor_id'         => 0,
					'status'           => 'pending',
					'retry_count'      => 4,
					'open_marker'      => 1,
					'lease_owner'      => null,
					'lease_expires_at' => null,
					'created_at'       => gmdate( 'Y-m-d H:i:s' ),
					'processed_at'     => null,
					'last_error'       => null,
				),
			)
		);
		// GET_LOCK contention makes invalidate_direct throw, exercising the failure path.
		$GLOBALS['lel_test_get_lock_result'] = '0';

		Invalidation_Queue::process_batch();

		$rows = self::rows();
		self::assertSame( 'failed', $rows[0]['status'], 'Exhausted retries must dead-letter the job.' );
		self::assertNull( $rows[0]['open_marker'], 'Dead-lettered jobs must release the unique open slot.' );
		self::assertNotEmpty( $rows[0]['last_error'] );

		unset( $GLOBALS['lel_test_get_lock_result'] );
		self::assertSame( 1, Invalidation_Queue::stats()['failed'] );
	}

	public function test_retriable_failure_keeps_open_marker_and_backs_off(): void {
		Invalidation_Queue::enqueue( array( 13 ), 'source_changed', 1 );
		$GLOBALS['lel_test_get_lock_result'] = '0';

		Invalidation_Queue::process_batch();

		$rows = self::rows();
		self::assertNotSame( 'failed', $rows[0]['status'], 'First failure must not dead-letter.' );
		self::assertNotSame( 'completed', $rows[0]['status'] );
		self::assertSame( 1, (int) $rows[0]['retry_count'] );
		self::assertSame( 1, (int) $rows[0]['open_marker'], 'Retriable jobs must keep their open slot.' );
		$delay = strtotime( (string) $rows[0]['lease_expires_at'] . ' UTC' ) - time();
		self::assertGreaterThanOrEqual( 25, $delay );
		self::assertLessThanOrEqual( 35, $delay, 'First retry backoff must be about 30 seconds.' );
	}

	public function test_retry_backoff_doubles_from_prior_attempt(): void {
		Invalidation_Queue::enqueue( array( 14 ), 'source_changed', 1 );
		$rows = self::rows();
		$rows[0]['retry_count'] = 1;
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_invalidation_queue', $rows );
		$GLOBALS['lel_test_get_lock_result'] = '0';

		Invalidation_Queue::process_batch();

		$row   = self::rows()[0];
		$delay = strtotime( (string) $row['lease_expires_at'] . ' UTC' ) - time();
		self::assertSame( 2, (int) $row['retry_count'] );
		self::assertGreaterThanOrEqual( 55, $delay );
		self::assertLessThanOrEqual( 65, $delay, 'Retry delay must grow exponentially and remain bounded.' );
	}

	public function test_lost_terminal_transition_fails_visibly(): void {
		Invalidation_Queue::enqueue( array( 15 ), 'source_changed', 1 );
		$GLOBALS['lel_test_fail_queue_transition'] = true;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'lease transition failed' );
		Invalidation_Queue::process_batch();
	}

	public function test_stats_report_processing_count(): void {
		$stats = Invalidation_Queue::stats();
		self::assertArrayHasKey( 'processing', $stats, 'Stats must expose in-flight jobs for observability.' );
	}
}
