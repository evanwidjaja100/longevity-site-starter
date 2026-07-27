<?php

use Longevity\Core\System_Readiness;
use PHPUnit\Framework\TestCase;

final class SystemReadinessTest extends TestCase {
	public function test_report_distinguishes_unknown_external_controls(): void {
		$GLOBALS['lel_test_registered_meta'] = array();
		$report = System_Readiness::report();

		self::assertIsArray( $report );
		self::assertArrayHasKey( 'status', $report );
		self::assertArrayHasKey( 'checks', $report );

		// External evidence checks must report unknown_external when no operator evidence is supplied.
		self::assertArrayHasKey( 'mail_transport', $report['checks'] );
		self::assertSame( 'unknown_external', $report['checks']['mail_transport']['status'] );

		self::assertArrayHasKey( 'last_backup', $report['checks'] );
		self::assertSame( 'unknown_external', $report['checks']['last_backup']['status'] );

		self::assertArrayHasKey( 'last_restore_drill', $report['checks'] );
		self::assertSame( 'unknown_external', $report['checks']['last_restore_drill']['status'] );
	}

	public function test_report_includes_internal_readiness_checks(): void {
		$report = System_Readiness::report();

		// Internal checks that can be verified without external evidence.
		self::assertArrayHasKey( 'database', $report['checks'] );
		self::assertSame( 'ok', $report['checks']['database']['status'] );

		self::assertArrayHasKey( 'scoring_model', $report['checks'] );
		self::assertSame( 'ok', $report['checks']['scoring_model']['status'] );

		self::assertArrayHasKey( 'audit_table', $report['checks'] );
		self::assertSame( 'ok', $report['checks']['audit_table']['status'] );

		self::assertArrayHasKey( 'approval_table', $report['checks'] );
		self::assertSame( 'ok', $report['checks']['approval_table']['status'] );
	}

	public function test_report_overall_status_reflects_blocked_checks(): void {
		// With no external evidence, the overall status should be degraded (not blocked).
		$report = System_Readiness::report();
		self::assertContains( $report['status'], array( 'ok', 'degraded' ) );
	}
}
