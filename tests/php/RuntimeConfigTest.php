<?php

use Longevity\Core\Runtime_Config;
use PHPUnit\Framework\TestCase;

final class RuntimeConfigTest extends TestCase {
	protected function tearDown(): void {
		putenv( 'LEL_CSP_MODE' );
		putenv( 'LEL_CSP_ENFORCE' );
	}

	public function test_packaged_scoring_model_is_valid(): void {
		Runtime_Config::reset();
		$status = Runtime_Config::scoring_model_status();
		self::assertTrue( $status['valid'], $status['message'] );
		self::assertNotSame( '', $status['model']['version'] ?? '' );
	}

	public function test_csp_mode_defaults_to_report_only_when_unset(): void {
		$status = Runtime_Config::csp_mode_status();
		self::assertSame( 'report-only', $status['mode'] );
		self::assertFalse( $status['configured'] );
		self::assertFalse( $status['invalid'] );
		self::assertFalse( $status['retired_key'] );
	}

	public function test_csp_mode_accepts_only_the_two_supported_values(): void {
		putenv( 'LEL_CSP_MODE=enforce' );
		$status = Runtime_Config::csp_mode_status();
		self::assertSame( 'enforce', $status['mode'] );
		self::assertTrue( $status['configured'] );

		putenv( 'LEL_CSP_MODE=Report-Only' );
		$status = Runtime_Config::csp_mode_status();
		self::assertSame( 'report-only', $status['mode'] );
		self::assertTrue( $status['configured'], 'Case and whitespace variants of supported values are normalized.' );
	}

	public function test_csp_mode_invalid_value_stays_report_only_but_is_flagged(): void {
		putenv( 'LEL_CSP_MODE=enforced' );
		$status = Runtime_Config::csp_mode_status();
		self::assertSame( 'report-only', $status['mode'], 'Unsupported values must never enable enforcement.' );
		self::assertFalse( $status['configured'] );
		self::assertTrue( $status['invalid'] );
	}

	public function test_csp_mode_empty_value_is_treated_as_invalid_configuration(): void {
		putenv( 'LEL_CSP_MODE=' );
		$status = Runtime_Config::csp_mode_status();
		self::assertSame( 'report-only', $status['mode'] );
		self::assertFalse( $status['configured'] );
		self::assertTrue( $status['invalid'] );
	}

	public function test_csp_mode_detects_retired_enforce_key(): void {
		putenv( 'LEL_CSP_ENFORCE=1' );
		$status = Runtime_Config::csp_mode_status();
		self::assertTrue( $status['retired_key'] );
		self::assertSame( 'report-only', $status['mode'], 'The retired key must never influence the effective mode.' );
	}
}
