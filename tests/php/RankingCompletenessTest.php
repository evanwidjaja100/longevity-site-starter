<?php

use Longevity\Core\Rankings;
use PHPUnit\Framework\TestCase;

/**
 * PR-05 complete and correctly ordered rankings.
 *
 * Invariants: the caller's limit is applied only after the complete eligible
 * population has been filtered and sorted, so a top-ranked review can never be
 * dropped merely because it appeared late in the candidate order. Category
 * counts span the complete eligible population. Above a configured safety
 * ceiling the projection fails closed rather than returning a partial ranking.
 */
final class RankingCompletenessTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_options']         = array();
		$GLOBALS['lel_test_meta']            = array();
		$GLOBALS['lel_test_titles']          = array();
		$GLOBALS['lel_test_post_categories'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['lel_test_options'],
			$GLOBALS['lel_test_meta'],
			$GLOBALS['lel_test_titles'],
			$GLOBALS['lel_test_post_categories']
		);
	}

	/** Seed a review's sortable metadata. */
	private function seedReview( int $id, float $score, string $confidence = 'High confidence', string $updated = '2026-01-01', bool $subscription = false ): void {
		$GLOBALS['lel_test_meta'][ $id ] = array(
			'review_score'            => $score,
			'review_score_confidence' => $confidence,
			'last_material_update'    => $updated,
			'subscription_required'   => $subscription ? '1' : '',
		);
		$GLOBALS['lel_test_titles'][ $id ] = 'Review ' . $id;
	}

	public function test_top_scored_review_beyond_limit_is_ranked_first(): void {
		// IDs 1..101 in candidate order; the LAST candidate has the top score.
		$ids = range( 1, 101 );
		foreach ( $ids as $id ) {
			$this->seedReview( $id, 3.0 );
		}
		$this->seedReview( 101, 5.0 );

		$ordered = Rankings::order_and_limit( $ids, 'score', array(), 100 );

		self::assertCount( 100, $ordered, 'The limit must cap the result at 100 after ordering.' );
		self::assertSame( 101, $ordered[0], 'The highest-scoring review must rank first even when it was the last candidate.' );
		self::assertContains( 101, $ordered, 'A top-ranked review must never be truncated by a pre-sort limit.' );
	}

	public function test_filtering_precedes_limiting(): void {
		// 120 low-confidence reviews first, then a few matching reviews at the tail.
		$ids = range( 1, 123 );
		foreach ( range( 1, 120 ) as $id ) {
			$this->seedReview( $id, 4.0, 'Low confidence' );
		}
		foreach ( array( 121, 122, 123 ) as $id ) {
			$this->seedReview( $id, 4.0, 'High confidence' );
		}

		$ordered = Rankings::order_and_limit( $ids, 'score', array( 'confidence' => 'High confidence' ), 100 );

		self::assertSame( array( 121, 122, 123 ), $ordered, 'Filtering must run over the whole population before the limit is applied.' );
	}

	public function test_no_hidden_cap_below_requested_limit(): void {
		$ids = range( 1, 250 );
		foreach ( $ids as $id ) {
			$this->seedReview( $id, 5.0 - ( $id / 1000 ) );
		}

		$ordered = Rankings::order_and_limit( $ids, 'score', array(), 250 );

		self::assertCount( 250, $ordered, 'No implicit 100/500 cap may reduce the population below the requested limit.' );
	}

	public function test_stable_tie_order_is_deterministic(): void {
		foreach ( array( 5, 3, 1, 4, 2 ) as $id ) {
			$this->seedReview( $id, 4.0, 'High confidence', '2026-01-01' );
		}
		$ids = array( 5, 3, 1, 4, 2 );

		$first  = Rankings::order_and_limit( $ids, 'score', array(), 10 );
		$second = Rankings::order_and_limit( array_reverse( $ids ), 'score', array(), 10 );

		self::assertSame( $first, $second, 'Equal reviews must resolve to the same deterministic order regardless of input order.' );
		self::assertSame( array( 1, 2, 3, 4, 5 ), $first, 'Ties must break by ascending ID.' );
	}

	public function test_population_ceiling_fails_closed(): void {
		$ids = range( 1, 11 );

		$result = Rankings::enforce_population_ceiling( $ids, 10 );

		self::assertSame( array(), $result, 'Exceeding the safety ceiling must fail closed rather than return a partial ranking.' );
		self::assertTrue( Rankings::is_degraded(), 'Breaching the ceiling must mark the ranking projection degraded.' );
	}

	public function test_population_within_ceiling_is_not_degraded(): void {
		$ids = range( 1, 10 );

		$result = Rankings::enforce_population_ceiling( $ids, 10 );

		self::assertSame( $ids, $result, 'A population at or below the ceiling must pass through unchanged.' );
		self::assertFalse( Rankings::is_degraded(), 'A within-ceiling population must clear any prior degraded flag.' );
	}

	public function test_directory_counts_span_complete_population(): void {
		$term = (object) array( 'term_id' => 7, 'name' => 'Sleep', 'slug' => 'sleep' );
		$ids  = range( 1, 150 );
		foreach ( $ids as $id ) {
			$this->seedReview( $id, 4.0 + ( $id / 1000 ), 'High confidence', '2026-02-' . str_pad( (string) ( ( $id % 28 ) + 1 ), 2, '0', STR_PAD_LEFT ) );
			$GLOBALS['lel_test_post_categories'][ $id ] = array( $term );
		}

		$groups = Rankings::aggregate_directory( $ids );

		self::assertCount( 1, $groups, 'All reviews share one category, so there must be exactly one group.' );
		self::assertSame( 150, $groups[0]['count'], 'Category counts must include every eligible review, not a capped subset.' );
	}
}
