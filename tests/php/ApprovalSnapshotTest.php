<?php

use Longevity\Core\Approval_Fingerprint;
use Longevity\Core\Publication_Gates;
use PHPUnit\Framework\TestCase;

final class ApprovalSnapshotTest extends TestCase {
	public function test_status_string_without_current_snapshot_blocks(): void {
		$context = array(
			'as_of_date' => '2026-07-21',
			'post_type' => 'post', 'content' => 'Complete content.', 'author_present' => true,
			'featured_image_alt_present' => true, 'content_summary' => 'Summary',
			'content_limitations' => 'Limitations', 'next_content_review_date' => '2027-01-01',
			'commercial_relationship' => 'none', 'editorial_approval_status' => 'ready',
			'editorial_approval_current' => false, 'material_health_claims' => false,
			'medical_review_required' => false, 'testing_required' => false,
			'affiliate_links_present' => false, 'original_contribution' => 'Framework',
			'region_scope' => 'Global', 'uncertainty_statement_present' => true,
			'claim_count' => 0, 'verified_claim_count' => 0, 'source_count' => 0, 'evidence_grade' => '',
		);
		self::assertContains( 'editorial_approval_stale', array_column( Publication_Gates::evaluate_values( $context )->blocking(), 'code' ) );
	}

	public function test_fingerprint_is_content_bound(): void {
		self::assertNotSame( Approval_Fingerprint::hash( array( 'content' => 'before' ) ), Approval_Fingerprint::hash( array( 'content' => 'after' ) ) );
	}
}
