<?php
/**
 * Roles reconciliation invariants (T01-03).
 *
 * Uses a real-shaped WP_Role stub (name/capabilities/has_cap/add_cap/remove_cap
 * only, exactly like core) so any call to nonexistent members fatals here
 * instead of in production.
 *
 * @package LongevityCore
 */

declare(strict_types=1);

use Longevity\Core\Audit_Log;
use Longevity\Core\Roles;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['lel_test_options'] = array();
		$GLOBALS['lel_test_roles']   = array(
			'administrator'        => new WP_Role( 'administrator', array( 'level_10' => true, 'manage_options' => true ) ),
			'editor'               => new WP_Role( 'editor', array( 'edit_posts' => true, 'publish_posts' => true ) ),
			'lel_managing_editor'  => new WP_Role( 'lel_managing_editor', array( 'read' => true, 'edit_posts' => true ) ),
			'lel_writer'           => new WP_Role( 'lel_writer', array( 'read' => true ) ),
			'lel_fact_checker'     => new WP_Role( 'lel_fact_checker', array( 'read' => true ) ),
			'lel_medical_reviewer' => new WP_Role( 'lel_medical_reviewer', array( 'read' => true ) ),
			'lel_product_tester'   => new WP_Role( 'lel_product_tester', array( 'read' => true ) ),
		);
		Audit_Log::set_test_mode( true );
	}

	protected function tearDown(): void {
		Audit_Log::set_test_mode( false );
		unset( $GLOBALS['lel_test_roles'], $GLOBALS['lel_test_options'] );
	}

	public function test_stale_custom_cap_is_removed_by_sweep(): void {
		// approve_publication_override is a managed custom cap NOT granted to lel_writer.
		$GLOBALS['lel_test_roles']['lel_writer']->add_cap( 'approve_publication_override' );

		$result = Roles::reconcile();

		$this->assertContains( 'lel_writer:approve_publication_override', $result['removed'] );
		$this->assertFalse( get_role( 'lel_writer' )->has_cap( 'approve_publication_override' ) );
	}

	public function test_reconcile_converges_from_drift(): void {
		$result = Roles::reconcile();

		$this->assertTrue( get_role( 'lel_writer' )->has_cap( 'submit_for_fact_check' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'approve_publication' ) );
		$this->assertTrue( get_role( 'lel_managing_editor' )->has_cap( 'approve_publication_override' ) );
		$this->assertNotEmpty( $result['added'] );
		$this->assertContains( 'lel_writer:submit_for_fact_check', $result['added'] );
	}

	public function test_reconcile_is_idempotent(): void {
		Roles::reconcile();
		$second = Roles::reconcile();

		$this->assertSame( array(), $second['added'] );
		$this->assertSame( array(), $second['removed'] );
		$this->assertGreaterThan( 0, $second['unchanged'] );
	}

	public function test_dry_run_reports_without_mutating(): void {
		$GLOBALS['lel_test_roles']['lel_writer']->add_cap( 'approve_publication_override' );

		$result = Roles::reconcile( true );

		$this->assertContains( 'lel_writer:approve_publication_override', $result['removed'] );
		$this->assertContains( 'lel_writer:submit_for_fact_check', $result['added'] );
		// No mutation happened.
		$this->assertTrue( get_role( 'lel_writer' )->has_cap( 'approve_publication_override' ) );
		$this->assertFalse( get_role( 'lel_writer' )->has_cap( 'submit_for_fact_check' ) );
		$this->assertArrayNotHasKey( 'lel_roles_reconciled_at', $GLOBALS['lel_test_options'] );
	}

	public function test_unrelated_caps_are_preserved(): void {
		$GLOBALS['lel_test_roles']['lel_writer']->add_cap( 'some_third_party_cap' );

		Roles::reconcile();

		$this->assertTrue( get_role( 'lel_writer' )->has_cap( 'some_third_party_cap' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_options' ) );
	}

	public function test_missing_role_is_reported_not_fatal(): void {
		unset( $GLOBALS['lel_test_roles']['lel_product_tester'] );

		$result = Roles::reconcile();

		$this->assertContains( 'lel_product_tester', $result['missing_roles'] );
		$this->assertArrayNotHasKey( 'lel_roles_reconciled_version', $GLOBALS['lel_test_options'] );
	}

	public function test_apply_records_reconciliation_markers(): void {
		Roles::reconcile();

		$this->assertSame( Roles::MATRIX_VERSION, $GLOBALS['lel_test_options']['lel_roles_reconciled_version'] );
		$this->assertArrayHasKey( 'lel_roles_reconciled_at', $GLOBALS['lel_test_options'] );
	}

	public function test_mandatory_audit_failure_never_marks_reconciliation_complete(): void {
		Audit_Log::set_test_fail_events( array( 'roles_reconciled' ) );

		try {
			Roles::reconcile();
			$this->fail( 'Mandatory audit failure must throw.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'mandatory audit failure', strtolower( $error->getMessage() ) );
		}
		$this->assertArrayNotHasKey( 'lel_roles_reconciled_version', $GLOBALS['lel_test_options'] );
		$this->assertArrayNotHasKey( 'lel_roles_reconciled_at', $GLOBALS['lel_test_options'] );
	}
}
