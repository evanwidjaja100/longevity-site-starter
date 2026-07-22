<?php

use Longevity\Core\Runtime_Config;
use PHPUnit\Framework\TestCase;

final class RuntimeConfigTest extends TestCase {
	public function test_packaged_scoring_model_is_valid(): void {
		Runtime_Config::reset();
		$status = Runtime_Config::scoring_model_status();
		self::assertTrue( $status['valid'], $status['message'] );
		self::assertNotSame( '', $status['model']['version'] ?? '' );
	}
}
