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

	public function test_sanitizes_bounded_public_results(): void {
		$projection = Review_Methodology::sanitize_public_results(
			array(
				array( 'label' => '<b>Battery</b>', 'observed_value' => '6.2', 'unit' => 'days', 'status' => 'meets', 'note' => '<script>alert(1)</script>Documented.', 'display_order' => 20, 'private_note' => 'must disappear' ),
				array( 'label' => 'Broken', 'observed_value' => 'x', 'status' => 'invented' ),
			)
		);
		self::assertCount( 1, $projection['rows'] );
		self::assertSame( 'Battery', $projection['rows'][0]['label'] );
		self::assertSame( 'alert(1)Documented.', $projection['rows'][0]['note'] );
		self::assertArrayNotHasKey( 'private_note', $projection['rows'][0] );
	}

	public function test_public_results_are_limited_to_thirty_rows(): void {
		$input = array_fill( 0, 40, array( 'label' => 'Metric', 'observed_value' => 'Value', 'status' => 'informational' ) );
		self::assertCount( 30, Review_Methodology::sanitize_public_results( $input )['rows'] );
	}
}
