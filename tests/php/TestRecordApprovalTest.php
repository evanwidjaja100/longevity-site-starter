<?php

use Longevity\Core\Review_Methodology;
use PHPUnit\Framework\TestCase;

final class TestRecordApprovalTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_meta'] = array(
			100 => array(
				'product_name' => 'Device',
				'unit_identifier' => 'unit-1',
				'acquisition_method' => 'purchased',
				'tester_user_ids' => '12',
				'test_start_date' => '2026-06-01',
				'test_end_date' => '2026-06-30',
				'protocol_id' => 'wearables',
				'protocol_version' => '1.0',
				'raw_observations' => 'Controlled observations.',
				'public_test_results' => array( array( 'label' => 'Battery', 'observed_value' => '6', 'status' => 'informational' ) ),
				'evidence_references' => 'controlled-ref',
				'submitted_by' => 13,
				'submitted_at' => '2026-07-01 12:00:00',
			),
			200 => array(
				'protocol_id' => 'wearables',
				'protocol_version' => '1.0',
				'product_category' => 'wearables',
				'effective_date' => '2026-01-01',
				'retired_date' => '',
				'minimum_test_duration' => '14 days',
				'required_observations' => 'Battery and fit.',
				'required_comparison_methods' => 'Reference device.',
				'required_environmental_conditions' => 'Controlled indoor use.',
				'required_disclosure_fields' => 'Acquisition method.',
				'scoring_dimensions' => array( array( 'name' => 'Accuracy', 'score' => 4, 'weight' => 100 ) ),
				'known_limitations' => 'Single-unit protocol.',
			),
		);
		$record = new WP_Post();
		$record->ID = 100;
		$record->post_type = 'lel_test_record';
		$record->post_author = '11';
		$protocol = new WP_Post();
		$protocol->ID = 200;
		$protocol->post_type = 'lel_protocol';
		$protocol->post_author = '11';
		$GLOBALS['lel_test_posts'] = array( 100 => $record, 200 => $protocol );
		$GLOBALS['lel_test_page_statuses'] = array( 100 => 'draft', 200 => 'draft' );
		$GLOBALS['lel_test_get_posts_result'] = array( 200 );
		$GLOBALS['lel_test_user_caps'] = array( 12 => array( 'approve_test_records' ), 14 => array( 'approve_test_records' ) );
		if ( ! Review_Methodology::approve_protocol( 200, 14 ) ) {
			throw new RuntimeException( 'Protocol fixture approval failed.' );
		}
	}

	public function test_rejects_tester_as_approver(): void {
		self::assertFalse( Review_Methodology::approve_test_record( 100, 12 ) );
		self::assertNotSame( 'approved', $GLOBALS['lel_test_meta'][100]['approval_status'] ?? '' );
	}

	public function test_approval_is_bound_to_current_record_hash(): void {
		self::assertTrue( Review_Methodology::approve_test_record( 100, 14 ) );
		self::assertSame( 'approved', $GLOBALS['lel_test_meta'][100]['approval_status'] );
		self::assertNotSame( '', $GLOBALS['lel_test_meta'][100]['approval_snapshot_hash'] );
		self::assertTrue( Review_Methodology::valid_test_record( 100, '1.0' ) );

		$GLOBALS['lel_test_meta'][100]['failures'] = 'Material change after approval.';
		self::assertFalse( Review_Methodology::valid_test_record( 100, '1.0' ) );
	}

	public function test_material_change_hook_marks_record_stale(): void {
		self::assertTrue( Review_Methodology::approve_test_record( 100, 14 ) );
		Review_Methodology::invalidate_test_record_approval( 1, 100, 'failures', 'changed' );
		self::assertSame( 'stale', $GLOBALS['lel_test_meta'][100]['approval_status'] );
	}
}
