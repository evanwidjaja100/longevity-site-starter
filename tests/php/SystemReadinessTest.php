<?php

use Longevity\Core\System_Readiness;
use PHPUnit\Framework\TestCase;

final class SystemReadinessTest extends TestCase {
	public function test_source_distinguishes_unknown_external_controls(): void {
		$source = (string) file_get_contents( LONGEVITY_CORE_PATH . 'class-system-readiness.php' );
		self::assertStringContainsString( 'unknown_external', $source );
		self::assertStringContainsString( 'lel_last_backup_evidence', $source );
		self::assertStringContainsString( 'lel_last_restore_drill_evidence', $source );
	}
}
