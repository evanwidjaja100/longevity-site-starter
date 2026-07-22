<?php

use Longevity\Core\Reviewer_Credentials;
use PHPUnit\Framework\TestCase;

final class ReviewerCredentialsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_user_caps'], $GLOBALS['lel_test_user_meta'] );
	}

	public function test_reviewer_cannot_verify_self(): void {
		$GLOBALS['lel_test_user_caps'][8] = array( 'verify_reviewer_credentials', 'complete_medical_review' );
		self::assertFalse( Reviewer_Credentials::can_verify( 8, 8 ) );
	}

	public function test_validity_requires_independent_current_scope_snapshot(): void {
		$GLOBALS['lel_test_user_caps'][8] = array( 'complete_medical_review' );
		$GLOBALS['lel_test_user_meta'][8] = array(
			'credential_verification_status' => 'verified',
			'credential_verification_date' => '2026-01-01',
			'credential_expiration_date' => '2027-01-01',
			'credential_verified_by_user_id' => 3,
			'verified_professional_credentials' => 'MD',
			'verified_review_scope' => 'safety_only, full_article',
			'verified_jurisdictions' => 'US, Global',
			'credential_verification_version' => Reviewer_Credentials::VERSION,
		);
		self::assertTrue( Reviewer_Credentials::is_valid_for( 8, 'safety_only', 'US', '2026-07-21' ) );
		self::assertFalse( Reviewer_Credentials::is_valid_for( 8, 'dosage_language_only', 'US', '2026-07-21' ) );
		self::assertFalse( Reviewer_Credentials::is_valid_for( 8, 'safety_only', 'US', '2027-01-02' ) );
	}
}
