<?php

use Longevity\Core\Date_Validator;
use PHPUnit\Framework\TestCase;

final class DateValidationTest extends TestCase {
	public function test_calendar_semantics_are_strict(): void {
		self::assertTrue( Date_Validator::is_valid( '2024-02-29' ) );
		self::assertFalse( Date_Validator::is_valid( '2025-02-29' ) );
		self::assertFalse( Date_Validator::is_valid( '2026-13-01' ) );
		self::assertSame( -1, Date_Validator::compare( '2026-01-01', '2026-01-02' ) );
	}
}
