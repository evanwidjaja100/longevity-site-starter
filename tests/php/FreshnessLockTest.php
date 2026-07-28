<?php

use Longevity\Core\Freshness;
use PHPUnit\Framework\TestCase;

final class FreshnessLockTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_options']    = array();
		$GLOBALS['lel_test_transients'] = array();
		$GLOBALS['lel_test_posts']      = array();
		$GLOBALS['lel_test_meta']       = array();
		$GLOBALS['wpdb']->lel_query_log = array();
		unset( $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_release_lock_calls'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_release_lock_calls'] );
	}

	public function test_contended_advisory_lock_skips_run(): void {
		$GLOBALS['lel_test_get_lock_result'] = '0';

		$report = Freshness::run();

		self::assertSame( 'locked', $report['status'], 'A contended advisory lock must skip the cycle.' );
		self::assertSame( 0, $report['processed'] );
		self::assertSame( '', (string) get_option( 'lel_cron_heartbeat_at', '' ), 'A skipped run must not touch state.' );
	}

	public function test_lock_error_fails_closed_and_counts(): void {
		$GLOBALS['lel_test_get_lock_result'] = null;

		$report = Freshness::run();

		self::assertSame( 'lock_error', $report['status'], 'A lock error must fail closed, never run unlocked.' );
		self::assertSame( 0, $report['processed'] );
		self::assertSame( 1, (int) get_option( 'lel_freshness_lock_errors', 0 ) );
	}

	public function test_acquired_lock_runs_and_releases(): void {
		$GLOBALS['lel_test_get_lock_result'] = '1';

		$report = Freshness::run();

		self::assertSame( 'ok', $report['status'] );
		self::assertSame( 1, (int) ( $GLOBALS['lel_test_release_lock_calls'] ?? 0 ), 'The advisory lock must be released in finally.' );
		self::assertNotSame( '', (string) get_option( 'lel_worker_heartbeat_freshness', '' ) );
	}

	public function test_worker_statuses_require_individual_schedule_and_heartbeat(): void {
		foreach ( array( 'lel_daily_freshness', 'lel_invalidation_queue_process', 'lel_notification_outbox_send', 'lel_contact_retention_cleanup' ) as $hook ) {
			$GLOBALS['lel_test_scheduled'][ $hook ] = time() + 60;
		}
		foreach ( array( 'freshness', 'invalidation', 'outbox', 'retention' ) as $worker ) {
			Freshness::record_worker_heartbeat( $worker );
		}

		self::assertSame( array( 'ok', 'ok', 'ok', 'ok' ), array_column( Freshness::worker_statuses(), 'status' ) );
		$GLOBALS['lel_test_options']['lel_worker_heartbeat_invalidation'] = '2000-01-01T00:00:00Z';
		self::assertSame( 'blocked', Freshness::worker_statuses()['invalidation']['status'] );
	}
}
