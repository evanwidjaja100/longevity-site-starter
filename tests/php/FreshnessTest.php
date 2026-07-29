<?php

use Longevity\Core\Freshness;
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

	public function test_flat_clause_is_applied_as_a_meta_condition(): void {
		$built = Freshness::build_meta_count_query( array( 'post', 'review' ), array( 'key' => '_lel_freshness_status', 'value' => 'update_due' ) );
		self::assertSame( '', $built['error'] );
		self::assertStringContainsString( 'INNER JOIN wp_postmeta pm0', $built['sql'] );
		self::assertStringContainsString( 'pm0.meta_value = %s', $built['sql'] );
		self::assertContains( '_lel_freshness_status', $built['params'] );
		self::assertContains( 'update_due', $built['params'] );
		self::assertSame( substr_count( $built['sql'], '%s' ), count( $built['params'] ), 'Placeholder count must equal parameter count.' );
	}

	public function test_not_exists_uses_left_join_and_is_null(): void {
		$built = Freshness::build_meta_count_query( 'lel_correction', array( array( 'key' => 'correction_status', 'compare' => 'NOT EXISTS' ) ) );
		self::assertSame( '', $built['error'] );
		self::assertStringContainsString( 'LEFT JOIN wp_postmeta pm0', $built['sql'] );
		self::assertStringContainsString( 'pm0.post_id IS NULL', $built['sql'] );
		self::assertStringNotContainsString( 'INNER JOIN', $built['sql'] );
	}

	public function test_any_status_emits_no_status_placeholder(): void {
		$built = Freshness::build_meta_count_query( array( 'post', 'review' ), array( 'key' => 'k', 'value' => 'v' ), 'any' );
		self::assertSame( '', $built['error'] );
		self::assertStringNotContainsString( 'post_status', $built['sql'] );
		self::assertSame( substr_count( $built['sql'], '%s' ), count( $built['params'] ), 'The sentinel status must never leave an unbound placeholder or an unplaced parameter.' );
	}

	public function test_explicit_statuses_bind_in_exact_order(): void {
		$today = gmdate( 'Y-m-d' );
		$built = Freshness::build_meta_count_query(
			array( 'post', 'review' ),
			array( array( 'key' => 'next_fact_check_date', 'value' => $today, 'compare' => '<', 'type' => 'DATE' ) ),
			array( 'publish', 'draft' )
		);
		self::assertSame( '', $built['error'] );
		self::assertStringContainsString( 'CAST(pm0.meta_value AS DATE) < %s', $built['sql'] );
		self::assertStringContainsString( 'p.post_status IN (%s, %s)', $built['sql'] );
		self::assertSame( array( 'next_fact_check_date', 'post', 'review', 'publish', 'draft', $today ), $built['params'] );
		self::assertSame( substr_count( $built['sql'], '%s' ), count( $built['params'] ) );
	}

	public function test_unsupported_operator_is_rejected(): void {
		$built = Freshness::build_meta_count_query( array( 'post' ), array( array( 'key' => 'k', 'value' => 'v', 'compare' => 'LIKE' ) ) );
		self::assertNull( $built['sql'] );
		self::assertNotSame( '', $built['error'] );
	}

	public function test_in_operator_expands_placeholder_list(): void {
		$built = Freshness::build_meta_count_query( array( 'post' ), array( array( 'key' => 'k', 'value' => array( 'a', 'b', 'c' ), 'compare' => 'IN' ) ) );
		self::assertSame( '', $built['error'] );
		self::assertStringContainsString( 'IN (%s, %s, %s)', $built['sql'] );
		self::assertSame( array( 'a', 'b', 'c' ), array_slice( $built['params'], -3 ) );
	}

	public function test_or_relation_combines_left_and_inner_joins(): void {
		$built = Freshness::build_meta_count_query(
			'lel_affiliate',
			array(
				'relation' => 'OR',
				array( 'key' => 'relationship_status', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'relationship_status', 'value' => 'active', 'compare' => '!=' ),
			)
		);
		self::assertSame( '', $built['error'] );
		self::assertStringContainsString( ' OR ', $built['sql'] );
		self::assertStringContainsString( 'LEFT JOIN', $built['sql'] );
		self::assertStringContainsString( 'INNER JOIN', $built['sql'] );
	}
}
