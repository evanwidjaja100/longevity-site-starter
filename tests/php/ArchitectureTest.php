<?php

use Longevity\Core\Tests\Support\ArchitectureAssertions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ArchitectureAssertions.php';

final class ArchitectureTest extends TestCase {
	public function test_architecture_constraints(): void {
		self::assertSame( array(), ArchitectureAssertions::failures(), implode( "\n", ArchitectureAssertions::failures() ) );
	}
}
