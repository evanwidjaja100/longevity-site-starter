<?php

use Longevity\Core\System_Readiness;
use PHPUnit\Framework\TestCase;

final class SystemReadinessTest extends TestCase {
	protected function tearDown(): void {
		putenv( 'LEL_CSP_MODE' );
		putenv( 'LEL_CSP_ENFORCE' );
		unset( $GLOBALS['lel_test_environment_type'] );
	}

	private static function csp_check(): array {
		return System_Readiness::report()['checks']['csp_mode'];
	}

	public function test_csp_mode_check_defaults_ok_outside_production(): void {
		$check = self::csp_check();
		self::assertSame( 'ok', $check['status'] );
		self::assertSame( 'report-only', $check['mode'] );
	}

	public function test_csp_mode_missing_blocks_production(): void {
		$GLOBALS['lel_test_environment_type'] = 'production';
		$check                                = self::csp_check();
		self::assertSame( 'blocked', $check['status'] );
		self::assertSame( 'report-only', $check['mode'] );
	}

	public function test_csp_report_only_blocks_production_launch_readiness(): void {
		$GLOBALS['lel_test_environment_type'] = 'production';
		putenv( 'LEL_CSP_MODE=report-only' );
		$check = self::csp_check();
		self::assertSame( 'blocked', $check['status'], 'Explicit report-only must still prevent final public-launch readiness in production.' );
	}

	public function test_csp_enforce_is_ready_in_production(): void {
		$GLOBALS['lel_test_environment_type'] = 'production';
		putenv( 'LEL_CSP_MODE=enforce' );
		$check = self::csp_check();
		self::assertSame( 'ok', $check['status'] );
		self::assertSame( 'enforce', $check['mode'] );
	}

	public function test_csp_invalid_value_blocks_production_and_degrades_elsewhere(): void {
		putenv( 'LEL_CSP_MODE=on' );
		$GLOBALS['lel_test_environment_type'] = 'production';
		self::assertSame( 'blocked', self::csp_check()['status'] );
		$GLOBALS['lel_test_environment_type'] = 'staging';
		self::assertSame( 'degraded', self::csp_check()['status'] );
	}

	public function test_csp_retired_key_blocks_everywhere(): void {
		putenv( 'LEL_CSP_ENFORCE=1' );
		putenv( 'LEL_CSP_MODE=enforce' );
		self::assertSame( 'blocked', self::csp_check()['status'], 'The retired key must be rejected even alongside a valid explicit mode.' );
	}

	public function test_csp_report_only_is_acceptable_in_staging(): void {
		$GLOBALS['lel_test_environment_type'] = 'staging';
		putenv( 'LEL_CSP_MODE=report-only' );
		self::assertSame( 'ok', self::csp_check()['status'], 'Private staging may run report-only during the observation window.' );
	}

	public function test_metrics_expose_effective_csp_mode_one_hot(): void {
		putenv( 'LEL_CSP_MODE=enforce' );
		$payload = \Longevity\Core\Metrics::render();
		self::assertStringContainsString( 'lel_csp_mode_state{mode="enforce"} 1', $payload );
		self::assertStringContainsString( 'lel_csp_mode_state{mode="report-only"} 0', $payload );
		putenv( 'LEL_CSP_MODE' );
		$payload = \Longevity\Core\Metrics::render();
		self::assertStringContainsString( 'lel_csp_mode_state{mode="report-only"} 1', $payload );
		self::assertStringContainsString( 'lel_csp_mode_explicit 0', $payload );
	}

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

	public function test_dependency_marker_cannot_hide_index_drift(): void {
		$saved_rows    = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_dependencies' );
		$saved_options = $GLOBALS['lel_test_options'] ?? array();
		try {
			$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_dependencies', array( array( 'id' => 1, 'dependency_type' => 'lel_claim', 'dependency_id' => 1, 'parent_post_id' => 999 ) ) );
			$GLOBALS['lel_test_options']['lel_dependency_index_backfilled_at'] = gmdate( DATE_W3C );
			$GLOBALS['lel_test_options']['lel_dependency_index_generation']    = \Longevity\Core\Dependency_Index::DATA_GENERATION;
			$GLOBALS['lel_test_options']['lel_dependency_index_schema_version'] = \Longevity\Core\Dependency_Index::SCHEMA_VERSION;

			$check = System_Readiness::report()['checks']['dependency_index'];
			self::assertSame( 'blocked', $check['status'] );
			self::assertFalse( $check['drift']['valid'] );
			self::assertSame( 1, $check['drift']['orphans'] );
		} finally {
			$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_dependencies', $saved_rows );
			$GLOBALS['lel_test_options'] = $saved_options;
		}
	}

	public function test_report_overall_status_reflects_blocked_checks(): void {
		$report = System_Readiness::report();
		self::assertNotSame( 'ok', $report['status'], 'Missing external/runtime identity must never be promotion-ready.' );
	}

	public function test_unrecognized_check_status_blocks_overall(): void {
		// A missing queue table makes queue_check emit a status outside the
		// closed enum; that must fail closed to blocked, never fall through.
		$saved = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_invalidation_queue' );
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_invalidation_queue' );
		try {
			$report = System_Readiness::report();
		} finally {
			$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_invalidation_queue', $saved );
		}
		self::assertSame( 'blocked', $report['checks']['invalidation_queue']['status'] );
		self::assertNotSame( 'ok', $report['status'] );
	}

	public function test_normalize_status_is_a_closed_fail_closed_enum(): void {
		self::assertSame( 'ok', System_Readiness::normalize_status( 'ok' ) );
		self::assertSame( 'degraded', System_Readiness::normalize_status( 'degraded' ) );
		self::assertSame( 'unknown_external', System_Readiness::normalize_status( 'unknown_external' ) );
		self::assertSame( 'blocked', System_Readiness::normalize_status( 'blocked' ) );
		self::assertSame( 'error', System_Readiness::normalize_status( 'error' ) );
		foreach ( array( 'unknown', 'OK', '', 'healthy', 'warn' ) as $unrecognized ) {
			self::assertSame( 'error', System_Readiness::normalize_status( $unrecognized ), "'$unrecognized' must normalize to error" );
		}
	}

	public function test_aggregation_is_max_severity(): void {
		$ok       = array( 'status' => 'ok' );
		$degraded = array( 'status' => 'degraded' );
		$unknown  = array( 'status' => 'unknown_external' );
		$blocked  = array( 'status' => 'blocked' );
		$error    = array( 'status' => 'error' );
		$garbage  = array( 'status' => 'not_registered' );
		$missing  = array( 'message' => 'no status key at all' );

		self::assertSame( 'ok', System_Readiness::aggregate( array( $ok, $ok ) ) );
		self::assertSame( 'degraded', System_Readiness::aggregate( array( $ok, $degraded ) ) );
		self::assertSame( 'unknown_external', System_Readiness::aggregate( array( $ok, $unknown ) ) );
		self::assertSame( 'blocked', System_Readiness::aggregate( array( $ok, $degraded, $blocked ) ) );
		self::assertSame( 'error', System_Readiness::aggregate( array( $ok, $blocked, $error ) ) );
		self::assertSame( 'error', System_Readiness::aggregate( array( $ok, $garbage ) ) );
		self::assertSame( 'error', System_Readiness::aggregate( array( $missing ) ) );
		self::assertSame( 'error', System_Readiness::aggregate( array() ) );
	}

	public function test_metrics_expose_one_hot_state_per_check(): void {
		$payload = \Longevity\Core\Metrics::render();
		self::assertStringNotContainsString( 'lel_readiness_check{check=', $payload, 'changing-status-label gauge must be replaced' );
		self::assertStringContainsString( '# TYPE lel_readiness_check_state gauge', $payload );

		preg_match_all( '/^lel_readiness_check_state\{check="([^"]+)",state="([^"]+)"\} ([01])$/m', $payload, $matches, PREG_SET_ORDER );
		self::assertNotEmpty( $matches );

		$states_seen = array();
		$sums        = array();
		$per_check   = array();
		foreach ( $matches as $m ) {
			$states_seen[ $m[2] ]           = true;
			$sums[ $m[1] ]                  = ( $sums[ $m[1] ] ?? 0 ) + (int) $m[3];
			$per_check[ $m[1] ][ $m[2] ]    = true;
		}
		self::assertSame( System_Readiness::CHECK_STATES, array_keys( $per_check['database'] ), 'fixed state set emitted in stable order' );
		foreach ( $sums as $check => $sum ) {
			self::assertSame( 1, $sum, "check '$check' must be one-hot across states" );
		}
		self::assertSame( System_Readiness::CHECK_STATES, array_keys( $states_seen ) );
		self::assertMatchesRegularExpression( '/^lel_build_info\{environment="[^"]+",source_sha="[^"]+",artifact_sha256="[^"]+"\} 1$/m', $payload );
		preg_match_all( '/^lel_readiness_overall_state\{state="[^"]+"\} ([01])$/m', $payload, $overall );
		self::assertSame( 1, array_sum( array_map( 'intval', $overall[1] ) ) );
	}
}
