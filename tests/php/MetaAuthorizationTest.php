<?php

use Longevity\Core\Meta_Authorization;
use Longevity\Core\Meta_Registry;
use PHPUnit\Framework\TestCase;

final class MetaAuthorizationTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_user_caps'], $GLOBALS['lel_test_meta'] );
		Meta_Authorization::exit_trusted_scope();
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

	public function test_generic_channel_also_denies_workflow_state_fields(): void {
		$GLOBALS['lel_test_user_caps'][5] = array( 'complete_fact_check', 'complete_medical_review', 'manage_test_records', 'approve_test_records', 'approve_commercial_disclosure', 'approve_publication' );

		foreach ( array( 'fact_check_status', 'medical_review_status', 'testing_status', 'editorial_approval_status' ) as $field ) {
			self::assertFalse( Meta_Authorization::can_write( $field, 44, 5, 'generic' ), $field );
		}
	}

	public function test_guard_allows_non_governed_keys(): void {
		$GLOBALS['lel_test_current_user_id'] = 7;
		$GLOBALS['lel_test_post_types'][44] = 'post';
		$GLOBALS['lel_test_user_caps'][7] = array( 'edit_post' );

		// Non-governed key should pass through (return null).
		self::assertNull( Meta_Authorization::guard_add( null, 44, '_thumbnail_id', 123, false ) );
		self::assertNull( Meta_Authorization::guard_update( null, 44, '_thumbnail_id', 123, null ) );
		self::assertNull( Meta_Authorization::guard_delete( null, 44, '_thumbnail_id', 123 ) );
	}

	public function test_guard_denies_governed_key_without_authorization(): void {
		$GLOBALS['lel_test_current_user_id'] = 0;
		$GLOBALS['lel_test_post_types'][44] = 'post';

		// No user, no trusted scope — should be denied.
		self::assertFalse( Meta_Authorization::guard_add( null, 44, 'content_summary', 'test', false ) );
		self::assertFalse( Meta_Authorization::guard_update( null, 44, 'content_summary', 'test', null ) );
		self::assertFalse( Meta_Authorization::guard_delete( null, 44, 'content_summary', 'test' ) );
	}

	public function test_guard_allows_authorized_editor(): void {
		$GLOBALS['lel_test_current_user_id'] = 7;
		$GLOBALS['lel_test_post_types'][44] = 'post';
		$GLOBALS['lel_test_user_caps'][7] = array( 'edit_post' );

		// Editor can write content_summary on their own post.
		self::assertNull( Meta_Authorization::guard_add( null, 44, 'content_summary', 'test', false ) );
		self::assertNull( Meta_Authorization::guard_update( null, 44, 'content_summary', 'test', null ) );
	}

	public function test_guard_denies_workflow_state_fields_even_for_authorized_user(): void {
		$GLOBALS['lel_test_current_user_id'] = 5;
		$GLOBALS['lel_test_post_types'][44] = 'post';
		$GLOBALS['lel_test_user_caps'][5] = array( 'approve_publication' );

		// Even an authorized user cannot write workflow state via direct update_post_meta.
		self::assertFalse( Meta_Authorization::guard_update( null, 44, 'editorial_approval_status', 'ready', null ) );
		self::assertFalse( Meta_Authorization::guard_add( null, 44, 'fact_check_status', 'complete', false ) );
	}

	public function test_guard_allows_in_trusted_scope(): void {
		$GLOBALS['lel_test_current_user_id'] = 0;
		$GLOBALS['lel_test_post_types'][44] = 'post';

		Meta_Authorization::enter_trusted_scope();
		try {
			// In trusted scope, even workflow state fields should pass through.
			self::assertNull( Meta_Authorization::guard_add( null, 44, 'editorial_approval_status', 'ready', false ) );
			self::assertNull( Meta_Authorization::guard_update( null, 44, 'fact_check_status', 'complete', null ) );
			self::assertNull( Meta_Authorization::guard_delete( null, 44, 'content_summary', 'test' ) );
		} finally {
			Meta_Authorization::exit_trusted_scope();
		}
	}

	public function test_trusted_scope_depth_is_counted(): void {
		self::assertFalse( Meta_Authorization::in_trusted_scope() );
		Meta_Authorization::enter_trusted_scope();
		self::assertTrue( Meta_Authorization::in_trusted_scope() );
		Meta_Authorization::enter_trusted_scope();
		self::assertTrue( Meta_Authorization::in_trusted_scope() );
		Meta_Authorization::exit_trusted_scope();
		self::assertTrue( Meta_Authorization::in_trusted_scope() );
		Meta_Authorization::exit_trusted_scope();
		self::assertFalse( Meta_Authorization::in_trusted_scope() );
	}
}
