<?php

use Longevity\Core\Approval_Fingerprint;
use PHPUnit\Framework\TestCase;

final class ApprovalFingerprintTest extends TestCase {
	public function test_map_order_and_line_endings_are_canonical(): void {
		$left  = array( 'b' => "two\r\nlines", 'a' => 1 );
		$right = array( 'a' => 1, 'b' => "two\nlines" );
		self::assertSame( Approval_Fingerprint::hash( $left ), Approval_Fingerprint::hash( $right ) );
	}

	public function test_material_change_changes_hash(): void {
		self::assertNotSame( Approval_Fingerprint::hash( array( 'content' => 'A' ) ), Approval_Fingerprint::hash( array( 'content' => 'B' ) ) );
	}
}
