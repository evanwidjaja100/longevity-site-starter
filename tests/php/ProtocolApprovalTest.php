<?php

use Longevity\Core\Review_Methodology;
use PHPUnit\Framework\TestCase;

final class ProtocolApprovalTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_meta'] = array(
			300 => array(
				'protocol_id' => 'consumer-apps',
				'protocol_version' => '2.0',
				'product_category' => 'apps',
				'effective_date' => '2026-01-01',
				'retired_date' => '',
				'minimum_test_duration' => '14 days',
				'required_observations' => 'Core workflow observations.',
				'required_comparison_methods' => 'Baseline comparison.',
				'required_environmental_conditions' => 'Supported devices.',
				'required_disclosure_fields' => 'Acquisition and conflicts.',
				'scoring_dimensions' => array( array( 'name' => 'Utility', 'score' => 4, 'weight' => 100 ) ),
				'known_limitations' => 'Small comparison set.',
			),
		);
		$protocol = new WP_Post();
		$protocol->ID = 300;
		$protocol->post_type = 'lel_protocol';
		$protocol->post_author = '21';
		$GLOBALS['lel_test_posts'] = array( 300 => $protocol );
		$GLOBALS['lel_test_page_statuses'] = array( 300 => 'draft' );
		$GLOBALS['lel_test_user_caps'] = array( 21 => array( 'approve_test_records' ), 22 => array( 'approve_test_records' ) );
	}

	public function test_protocol_author_cannot_self_approve(): void {
		self::assertFalse( Review_Methodology::approve_protocol( 300, 21 ) );
	}

	public function test_independent_approval_is_bound_to_protocol_hash(): void {
		self::assertTrue( Review_Methodology::approve_protocol( 300, 22 ) );
		self::assertSame( 'approved', $GLOBALS['lel_test_meta'][300]['approval_status'] );
		self::assertSame( 22, $GLOBALS['lel_test_meta'][300]['protocol_reviewer_user_id'] );
		self::assertNotSame( '', $GLOBALS['lel_test_meta'][300]['approval_snapshot_hash'] );

		$GLOBALS['lel_test_meta'][300]['known_limitations'] = 'Materially changed limitations.';
		self::assertNotSame( $GLOBALS['lel_test_meta'][300]['approval_snapshot_hash'], Review_Methodology::protocol_fingerprint( 300 ) );
	}

	public function test_material_change_hook_marks_protocol_stale(): void {
		self::assertTrue( Review_Methodology::approve_protocol( 300, 22 ) );
		Review_Methodology::invalidate_test_record_approval( 1, 300, 'known_limitations', 'changed' );
		self::assertSame( 'stale', $GLOBALS['lel_test_meta'][300]['approval_status'] );
	}
}
