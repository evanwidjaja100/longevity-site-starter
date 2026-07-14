<?php

use Longevity\Core\Meta_Registry;
use PHPUnit\Framework\TestCase;

final class MetaRegistryTest extends TestCase {
	public function test_evidence_grade_is_allowlisted(): void {
		self::assertSame( 'B', Meta_Registry::sanitize_value( 'evidence_grade', 'B' ) );
		self::assertSame( '', Meta_Registry::sanitize_value( 'evidence_grade', 'Excellent' ) );
	}

	public function test_date_rejects_non_iso_values(): void {
		self::assertSame( '2026-07-14', Meta_Registry::sanitize_value( 'date', '2026-07-14' ) );
		self::assertSame( '', Meta_Registry::sanitize_value( 'date', '14/07/2026' ) );
	}

	public function test_score_is_clamped(): void {
		self::assertSame( 5.0, Meta_Registry::sanitize_value( 'score', 8 ) );
		self::assertSame( 0.0, Meta_Registry::sanitize_value( 'score', -2 ) );
	}
}
