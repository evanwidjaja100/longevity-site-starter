<?php

use Longevity\Core\Freshness_Repository;
use PHPUnit\Framework\TestCase;

final class FreshnessTest extends TestCase {
	public function test_repeated_batches_eventually_visit_every_record(): void {
		$records = array();
		for ( $id = 1; $id <= 35; ++$id ) {
			$records[] = array( 'id' => $id, 'last_scanned_at' => '' );
		}
		$visited = array();
		for ( $run = 1; $run <= 4; ++$run ) {
			$batch = Freshness_Repository::select_fair_batch( $records, 10 );
			foreach ( $batch as $selected ) {
				$visited[ $selected['id'] ] = true;
				foreach ( $records as &$record ) {
					if ( $record['id'] === $selected['id'] ) {
						$record['last_scanned_at'] = sprintf( '2026-07-%02d 00:00:00', $run );
					}
				}
				unset( $record );
			}
			// A modification does not erase scan fairness state.
			$records[0]['modified_at'] = '2026-07-31 00:00:00';
		}
		self::assertCount( 35, $visited );
	}
}
