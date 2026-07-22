<?php

use Longevity\Core\Meta_Authorization;
use Longevity\Core\Meta_Registry;
use PHPUnit\Framework\TestCase;

final class MetaAuthorizationTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_user_caps'], $GLOBALS['lel_test_meta'] );
	}

	public function test_every_registered_field_has_explicit_non_deny_policy(): void {
		foreach ( Meta_Registry::definitions() as $key => $definition ) {
			self::assertArrayHasKey( 'write_policy', $definition, $key );
			self::assertNotSame( 'deny', $definition['write_policy'], $key );
		}
	}

	public function test_post_editor_cannot_lower_risk_requirements(): void {
		$GLOBALS['lel_test_user_caps'][7] = array( 'edit_post' );
		self::assertFalse( Meta_Authorization::can_write( 'medical_review_required', 44, 7, 'rest' ) );
		self::assertFalse( Meta_Authorization::can_write( 'material_health_claims', 44, 7, 'classic' ) );
		self::assertTrue( Meta_Authorization::can_write( 'content_summary', 44, 7, 'rest' ) );
	}

	public function test_unknown_and_system_fields_deny_by_default(): void {
		$GLOBALS['lel_test_user_caps'][1] = array( 'approve_publication' );
		self::assertFalse( Meta_Authorization::can_write( 'unknown_field', 1, 1, 'rest' ) );
		self::assertFalse( Meta_Authorization::can_write( 'fact_checked_by', 1, 1, 'rest' ) );
	}

	public function test_rest_cannot_write_any_final_workflow_projection(): void {
		$GLOBALS['lel_test_user_caps'][5] = array( 'complete_fact_check', 'complete_medical_review', 'manage_test_records', 'approve_test_records', 'approve_commercial_disclosure', 'approve_publication' );
		$GLOBALS['lel_test_meta'][44]['medical_reviewer_user_id'] = 5;

		foreach ( array( 'fact_check_status', 'medical_review_status', 'medical_review_attested', 'testing_status', 'affiliate_disclosure_status', 'editorial_approval_status' ) as $field ) {
			self::assertFalse( Meta_Authorization::can_write( $field, 44, 5, 'rest' ), $field );
		}
	}
}
