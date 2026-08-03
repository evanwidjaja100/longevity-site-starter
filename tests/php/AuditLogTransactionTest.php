<?php
/**
 * Audit transaction and invalidation outbox tests.
 *
 * @package LongevityCore
 */

use Longevity\Core\Audit_Log;
use Longevity\Core\Invalidation_Queue;
use PHPUnit\Framework\TestCase;

final class AuditLogTransactionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_audit_events', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_invalidation_queue', array() );
		$GLOBALS['wpdb']->lel_query_log     = array();
		$GLOBALS['wpdb']->lel_audit_sequence = 0;
		$GLOBALS['wpdb']->last_error        = '';
		$GLOBALS['lel_test_options']        = array();
		$GLOBALS['lel_test_scheduled']      = array();
		unset( $GLOBALS['lel_test_fail_commit'], $GLOBALS['lel_test_throw_on_insert'], $GLOBALS['lel_test_fail_insert'], $GLOBALS['lel_test_fail_queue_insert'], $GLOBALS['lel_test_fail_audit_sequence'] );
		Audit_Log::set_test_mode( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_fail_commit'], $GLOBALS['lel_test_throw_on_insert'], $GLOBALS['lel_test_fail_insert'], $GLOBALS['lel_test_fail_queue_insert'], $GLOBALS['lel_test_fail_audit_sequence'] );
		$GLOBALS['wpdb']->last_error = '';
		Audit_Log::set_test_mode( true );
	}

	private static function log(): array {
		return $GLOBALS['wpdb']->lel_query_log;
	}

	private static function rollback_count(): int {
		return count( array_filter( self::log(), static fn( string $sql ): bool => 0 === strcasecmp( trim( $sql ), 'ROLLBACK' ) ) );
	}

	public function test_successful_write_commits_and_returns_id(): void {
		$id = Audit_Log::record( 'test_event', 'post', 12, array( 'k' => 'v' ), 3, 'system' );

		self::assertGreaterThan( 0, $id, 'A healthy write must return the insert ID.' );
		$log = array_map( 'trim', self::log() );
		self::assertContains( 'START TRANSACTION', $log );
		self::assertContains( 'COMMIT', $log );
		self::assertSame( 0, self::rollback_count(), 'A committed write must not roll back.' );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_audit_events' ) );
		self::assertTrue( Audit_Log::verify_chain()['valid'], 'New schema hashes must verify with a nullable idempotency key.' );
	}

	public function test_sequence_does_not_reuse_unrelated_insert_id(): void {
		$GLOBALS['wpdb']->insert_id = 9001;

		Audit_Log::record( 'first_event', 'post', 12, array(), 3, 'system', true );
		$GLOBALS['wpdb']->insert_id = 12001;
		Audit_Log::record( 'second_event', 'post', 12, array(), 3, 'system', true );

		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_audit_events' );
		self::assertSame( array( 1, 2 ), array_map( static fn( array $row ): int => (int) $row['sequence'], $rows ) );
		self::assertTrue( Audit_Log::verify_chain()['valid'] );
	}

	public function test_sequence_mutex_is_taken_before_predecessor_read(): void {
		Audit_Log::record( 'test_event', 'post', 12, array(), 3, 'system', true );

		$log       = self::log();
		$mutex_pos = array_search( 'UPDATE wp_lel_audit_sequence SET current_value = LAST_INSERT_ID(current_value + 1) WHERE id = 1', $log, true );
		$tail_pos  = array_search( 'SELECT event_hash FROM wp_lel_audit_events ORDER BY sequence DESC LIMIT 1', $log, true );
		self::assertIsInt( $mutex_pos );
		self::assertIsInt( $tail_pos );
		self::assertLessThan( $tail_pos, $mutex_pos );
	}

	public function test_commit_failure_issues_rollback_and_records_failure(): void {
		$GLOBALS['lel_test_fail_commit'] = true;

		$id = Audit_Log::record( 'test_event', 'post', 12, array(), 3, 'system' );

		self::assertSame( 0, $id, 'Commit failure must not report success.' );
		self::assertGreaterThanOrEqual( 1, self::rollback_count(), 'A failed COMMIT must be followed by an explicit ROLLBACK.' );
		self::assertSame( 1, Audit_Log::failure_count(), 'A commit failure must increment the failure counter.' );
		self::assertFalse( Audit_Log::request_is_healthy(), 'Unknown COMMIT outcome must quarantine the request.' );
	}

	public function test_unknown_commit_outcome_blocks_later_audit_write(): void {
		$GLOBALS['lel_test_fail_commit'] = true;
		Audit_Log::record( 'first_event', 'post', 12 );
		$GLOBALS['lel_test_fail_commit'] = false;
		$starts_before = count( array_filter( self::log(), static fn( string $sql ): bool => 'START TRANSACTION' === trim( $sql ) ) );

		$id = Audit_Log::record( 'second_event', 'post', 12 );

		self::assertSame( 0, $id );
		self::assertSame( $starts_before, count( array_filter( self::log(), static fn( string $sql ): bool => 'START TRANSACTION' === trim( $sql ) ) ), 'Blocked writes must not start another transaction.' );
	}

	public function test_mid_write_exception_issues_rollback_and_does_not_escape(): void {
		$GLOBALS['lel_test_throw_on_insert'] = true;

		$id = Audit_Log::record( 'test_event', 'post', 12, array(), 3, 'system' );

		self::assertSame( 0, $id, 'A mid-write exception on a non-mandatory event must yield 0, not escape.' );
		self::assertGreaterThanOrEqual( 1, self::rollback_count(), 'A mid-write exception must trigger ROLLBACK.' );
		self::assertSame( 1, Audit_Log::failure_count() );
	}

	public function test_mandatory_commit_failure_still_throws_after_rollback(): void {
		$GLOBALS['lel_test_fail_commit'] = true;

		$thrown = null;
		try {
			Audit_Log::record( 'test_event', 'post', 12, array(), 3, 'system', true );
		} catch ( \RuntimeException $error ) {
			$thrown = $error;
		}

		self::assertNotNull( $thrown, 'Mandatory events must fail closed with an exception.' );
		self::assertGreaterThanOrEqual( 1, self::rollback_count(), 'Mandatory failure path must also roll back.' );
	}

	public function test_mandatory_mid_write_exception_still_throws_after_rollback(): void {
		$GLOBALS['lel_test_throw_on_insert'] = true;

		$thrown = null;
		try {
			Audit_Log::record( 'test_event', 'post', 12, array(), 3, 'system', true );
		} catch ( \RuntimeException $error ) {
			$thrown = $error;
		}

		self::assertNotNull( $thrown, 'Mandatory events must fail closed when the write throws mid-transaction.' );
		self::assertGreaterThanOrEqual( 1, self::rollback_count() );
	}

	public function test_sequence_failure_rolls_back_and_surfaces(): void {
		$GLOBALS['lel_test_fail_audit_sequence'] = true;

		$id = Audit_Log::record( 'test_event', 'post', 12 );

		self::assertSame( 0, $id );
		self::assertGreaterThanOrEqual( 1, self::rollback_count() );
		self::assertSame( 1, Audit_Log::failure_count() );
	}

	public function test_idempotency_key_replay_returns_original_event(): void {
		$first  = Audit_Log::record( 'approval_invalidated', 'post', 12, array(), 3, 'system', true, 'invalidation_queue:44' );
		$second = Audit_Log::record( 'approval_invalidated', 'post', 12, array(), 3, 'system', true, 'invalidation_queue:44' );

		self::assertSame( $first, $second );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_audit_events' ), 'Outbox replay must not append a duplicate audit event.' );
	}

	public function test_idempotent_replay_recovers_unknown_commit_without_duplicate(): void {
		$GLOBALS['lel_test_fail_commit'] = true;
		self::assertSame( 0, Audit_Log::record( 'approval_invalidated', 'post', 12, array(), 3, 'system', false, 'invalidation_queue:45' ) );
		$GLOBALS['lel_test_fail_commit'] = false;
		Audit_Log::set_test_mode( false ); // Simulate the next request after quarantine.

		$id = Audit_Log::record( 'approval_invalidated', 'post', 12, array(), 3, 'system', true, 'invalidation_queue:45' );

		self::assertGreaterThan( 0, $id );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_audit_events' ) );
	}

	public function test_queue_leaves_recoverable_outbox_row_after_unknown_audit_commit(): void {
		Invalidation_Queue::enqueue( array( 46 ), 'dependency_changed:test', 1 );
		$GLOBALS['lel_test_fail_commit'] = true;

		Invalidation_Queue::process_batch();

		$row = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_invalidation_queue' )[0];
		self::assertSame( 'processing', $row['status'] );
		self::assertSame( 1, (int) $row['open_marker'] );
		self::assertSame( 0, (int) $row['retry_count'], 'No governance transition may follow an unknown COMMIT outcome in the same request.' );
		self::assertFalse( Audit_Log::request_is_healthy() );
	}

	public function test_enqueue_in_transaction_wraps_batch_and_schedules_after_commit(): void {
		Invalidation_Queue::enqueue_in_transaction( array( 5, 6 ), 'dependency_changed:test', 1 );

		$log        = array_map( 'trim', self::log() );
		$tx_start   = array_search( 'START TRANSACTION', $log, true );
		$commit_pos = array_search( 'COMMIT', $log, true );
		self::assertNotFalse( $tx_start, 'Batch enqueue must open a transaction.' );
		self::assertNotFalse( $commit_pos, 'Batch enqueue must commit.' );
		$insert_positions = array_keys( array_filter( $log, static fn( string $sql ): bool => (bool) preg_match( '/^INSERT INTO wp_lel_invalidation_queue/i', $sql ) ) );
		self::assertCount( 2, $insert_positions );
		foreach ( $insert_positions as $pos ) {
			self::assertGreaterThan( $tx_start, $pos, 'Queue inserts must run inside the transaction.' );
			self::assertLessThan( $commit_pos, $pos, 'Queue inserts must run before COMMIT.' );
		}
		self::assertSame( 0, self::rollback_count() );
		self::assertNotEmpty( $GLOBALS['lel_test_scheduled'] ?? array(), 'Processing must be scheduled after commit.' );

		$open = array_filter( $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_invalidation_queue' ), static fn( array $row ): bool => ! empty( $row['open_marker'] ) );
		self::assertCount( 2, $open );
	}

	public function test_enqueue_in_transaction_rolls_back_and_throws_on_insert_failure(): void {
		$GLOBALS['lel_test_fail_queue_insert'] = true;

		$thrown = null;
		try {
			Invalidation_Queue::enqueue_in_transaction( array( 5, 6 ), 'dependency_changed:test', 1 );
		} catch ( \RuntimeException $error ) {
			$thrown = $error;
		}

		self::assertNotNull( $thrown, 'A failed enqueue batch must surface as an exception, never be silently dropped.' );
		self::assertGreaterThanOrEqual( 1, self::rollback_count(), 'A failed enqueue batch must roll back.' );
		self::assertEmpty( $GLOBALS['lel_test_scheduled'] ?? array(), 'Nothing may be scheduled when the batch fails.' );
	}
}
