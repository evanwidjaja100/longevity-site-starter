<?php

use Longevity\Core\Rankings;
use PHPUnit\Framework\TestCase;

final class RankingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_meta'] = array(
			1 => array( 'review_score' => 4.0, 'review_score_confidence' => 'Moderate confidence', 'last_material_update' => '2026-07-01' ),
			2 => array( 'review_score' => 4.0, 'review_score_confidence' => 'High confidence', 'last_material_update' => '2026-06-01' ),
			3 => array( 'review_score' => 4.0, 'review_score_confidence' => 'High confidence', 'last_material_update' => '2026-07-01' ),
			4 => array( 'review_score' => 4.0, 'review_score_confidence' => 'High confidence', 'last_material_update' => '2026-07-01' ),
		);
		$GLOBALS['lel_test_titles'] = array( 1 => 'Delta', 2 => 'Charlie', 3 => 'Bravo', 4 => 'Alpha' );
	}

	public function test_default_order_uses_confidence_update_and_title_tiebreakers(): void {
		$posts = array_map( static fn( $id ) => (object) array( 'ID' => $id ), array( 1, 2, 3, 4 ) );
		self::assertSame( array( 4, 3, 2, 1 ), array_map( static fn( $post ) => $post->ID, Rankings::sort( $posts ) ) );
	}

	public function test_title_order_is_stable_and_deterministic(): void {
		$posts = array_map( static fn( $id ) => (object) array( 'ID' => $id ), array( 2, 1, 4, 3 ) );
		self::assertSame( array( 4, 3, 2, 1 ), array_map( static fn( $post ) => $post->ID, Rankings::sort( $posts, 'title' ) ) );
	}

	public function test_unknown_sort_falls_back_to_score(): void {
		$posts = array_map( static fn( $id ) => (object) array( 'ID' => $id ), array( 1, 2, 3, 4 ) );
		self::assertSame( array( 4, 3, 2, 1 ), array_map( static fn( $post ) => $post->ID, Rankings::sort( $posts, 'post__in DESC; DROP TABLE' ) ) );
	}
}
