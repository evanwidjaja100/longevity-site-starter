<?php

use Longevity\Core\Review_Methodology;
use PHPUnit\Framework\TestCase;

final class ReviewMethodologyTest extends TestCase {
	public function test_calculates_weighted_score(): void {
		$result = Review_Methodology::calculate_score(
			array(
				array( 'name' => 'Comfort', 'score' => 4, 'weight' => 40 ),
				array( 'name' => 'Reliability', 'score' => 3, 'weight' => 60 ),
			)
		);
		self::assertSame( 3.4, $result['score'] );
	}

	public function test_rejects_weights_that_do_not_total_one_hundred(): void {
		$this->expectException( InvalidArgumentException::class );
		Review_Methodology::calculate_score( array( array( 'name' => 'Comfort', 'score' => 4, 'weight' => 80 ) ) );
	}

	public function test_sanitizes_score_bounds(): void {
		$dimensions = Review_Methodology::sanitize_dimensions( array( array( 'name' => '<b>Battery</b>', 'score' => 9, 'weight' => 120 ) ) );
		self::assertSame( 'Battery', $dimensions[0]['name'] );
		self::assertSame( 5.0, $dimensions[0]['score'] );
		self::assertSame( 100.0, $dimensions[0]['weight'] );
	}
}
