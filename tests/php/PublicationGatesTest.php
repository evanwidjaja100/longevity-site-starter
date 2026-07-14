<?php

use Longevity\Core\Publication_Gates;
use PHPUnit\Framework\TestCase;

final class PublicationGatesTest extends TestCase {
	private function validContext(): array {
		return array(
			'post_type' => 'post',
			'content' => 'Complete educational content.',
			'author_present' => true,
			'featured_image_alt_present' => true,
			'content_summary' => 'A clear direct answer with an explicit educational scope.',
			'content_limitations' => 'The evidence is population-specific and cannot establish individual outcomes.',
			'next_content_review_date' => '2027-01-14',
			'commercial_relationship' => 'none',
			'editorial_approval_status' => 'ready',
			'material_health_claims' => false,
			'medical_review_required' => false,
			'testing_required' => false,
			'affiliate_links_present' => false,
			'original_contribution' => 'A decision framework.',
			'region_scope' => 'Global educational scope',
			'uncertainty_statement_present' => true,
			'claim_count' => 0,
			'verified_claim_count' => 0,
			'source_count' => 0,
			'evidence_grade' => '',
		);
	}

	public function test_valid_low_risk_content_is_not_blocked(): void {
		$result = Publication_Gates::evaluate_values( $this->validContext() );
		self::assertFalse( $result->is_blocked() );
	}

	public function test_missing_summary_blocks_publication(): void {
		$context = $this->validContext();
		$context['content_summary'] = '';
		$result = Publication_Gates::evaluate_values( $context );
		self::assertTrue( $result->is_blocked() );
		self::assertContains( 'missing_summary', array_column( $result->blocking(), 'code' ) );
	}

	public function test_material_health_claims_require_verified_claims_and_fact_check(): void {
		$context = $this->validContext();
		$context['material_health_claims'] = true;
		$context['fact_check_status'] = 'not_started';
		$context['claim_count'] = 1;
		$context['verified_claim_count'] = 0;
		$result = Publication_Gates::evaluate_values( $context );
		$codes = array_column( $result->blocking(), 'code' );
		self::assertContains( 'fact_check_incomplete', $codes );
		self::assertContains( 'claims_unverified', $codes );
	}

	public function test_testing_claims_require_approved_record(): void {
		$context = $this->validContext();
		$context['post_type'] = 'review';
		$context['testing_required'] = true;
		$context['testing_status'] = 'complete';
		$context['testing_start_date'] = '2026-06-01';
		$context['testing_end_date'] = '2026-06-30';
		$context['testing_protocol_version'] = '1.0.0';
		$context['testing_methodology_url'] = 'https://publication.test/method';
		$context['product_acquisition_method'] = 'purchased';
		$context['tested_product_model'] = 'Specific Model';
		$context['comparison_set'] = 'Diary comparison';
		$context['test_record_valid'] = false;
		$result = Publication_Gates::evaluate_values( $context );
		self::assertContains( 'test_record_invalid', array_column( $result->blocking(), 'code' ) );
	}

	public function test_review_score_must_recalculate_from_dimensions(): void {
		$context = $this->validContext();
		$context['post_type'] = 'review';
		$context['tested_product_model'] = 'Specific Model';
		$context['comparison_set'] = 'A defined comparison set';
		$context['review_score'] = 4.5;
		$context['review_score_version'] = '1.0.0';
		$context['review_score_confidence'] = 'Moderate confidence';
		$context['review_score_dimensions'] = array(
			array( 'name' => 'Comfort', 'score' => 4, 'weight' => 50 ),
			array( 'name' => 'Reliability', 'score' => 3, 'weight' => 50 ),
		);
		$result = Publication_Gates::evaluate_values( $context );
		self::assertContains( 'score_not_reproducible', array_column( $result->blocking(), 'code' ) );
	}

	public function test_medical_review_requires_scoped_record_fields(): void {
		$context = $this->validContext();
		$context['material_health_claims'] = true;
		$context['medical_review_required'] = true;
		$context['fact_check_status'] = 'complete';
		$context['fact_checked_by'] = 5;
		$context['fact_checked_date'] = '2026-07-14';
		$context['claim_count'] = 1;
		$context['verified_claim_count'] = 1;
		$context['medical_review_status'] = 'complete';
		$context['medical_review_scope'] = 'safety_only';
		$context['medical_review_date'] = '2026-07-14';
		$context['next_medical_review_date'] = '2027-01-14';
		$context['medical_review_version'] = '1.0.0';
		$context['medical_review_attested'] = true;
		$context['medical_reviewer_valid'] = true;
		$result = Publication_Gates::evaluate_values( $context );
		$codes = array_column( $result->blocking(), 'code' );
		self::assertContains( 'medical_medical_review_sections', $codes );
		self::assertContains( 'medical_medical_review_limitations', $codes );
		self::assertContains( 'medical_medical_review_conflicts', $codes );
	}
}
