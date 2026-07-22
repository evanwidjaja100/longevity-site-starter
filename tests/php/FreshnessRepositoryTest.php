<?php

use Longevity\Core\Freshness_Repository;
use PHPUnit\Framework\TestCase;

final class FreshnessRepositoryTest extends TestCase {
	public function test_never_scanned_then_oldest_records_are_selected(): void {
		$records = array(
			array( 'id' => 4, 'last_scanned_at' => '2026-07-20 00:00:00' ),
			array( 'id' => 2, 'last_scanned_at' => '' ),
			array( 'id' => 3, 'last_scanned_at' => '2026-07-01 00:00:00' ),
			array( 'id' => 1, 'last_scanned_at' => '' ),
		);
		$selected = Freshness_Repository::select_fair_batch( $records, 3 );
		self::assertSame( array( 1, 2, 3 ), array_column( $selected, 'id' ) );
	}
}
