<?php

use Longevity\Core\Advisory_Lock;
use Longevity\Core\Migrations;
use PHPUnit\Framework\TestCase;

final class MigrationsLockTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_options']            = array( 'lel_data_version' => 0 );
		$GLOBALS['lel_test_release_lock_calls'] = 0;
		unset( $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_data_version_on_lock'], $GLOBALS['lel_test_fail_data_version_update'], $GLOBALS['lel_test_missing_schema_column'], $GLOBALS['lel_test_missing_schema_index'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_options'], $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_release_lock_calls'], $GLOBALS['lel_test_data_version_on_lock'], $GLOBALS['lel_test_fail_data_version_update'], $GLOBALS['lel_test_missing_schema_column'], $GLOBALS['lel_test_missing_schema_index'] );
	}

	public function test_contended_lock_refuses_all_migrations(): void {
		$GLOBALS['lel_test_get_lock_result'] = '0';

		$result = Migrations::run_migrations();

		self::assertFalse( $result['success'] );
		self::assertSame( array(), $result['migrated'] );
		self::assertSame( Advisory_Lock::CONTENDED, $result['lock_state'] );
		self::assertStringContainsString( 'another process', $result['error'] );
		self::assertSame( 0, $GLOBALS['lel_test_options']['lel_data_version'], 'Data version must not advance under contention.' );
	}

	public function test_lock_error_refuses_all_migrations(): void {
		$GLOBALS['lel_test_get_lock_result'] = null;

		$result = Migrations::run_migrations();

		self::assertFalse( $result['success'] );
		self::assertSame( array(), $result['migrated'] );
		self::assertSame( Advisory_Lock::ERROR, $result['lock_state'] );
		self::assertSame( 0, $GLOBALS['lel_test_options']['lel_data_version'] );
	}

	public function test_force_does_not_override_real_contention(): void {
		$GLOBALS['lel_test_get_lock_result'] = '0';

		$result = Migrations::run_migrations( true );

		self::assertFalse( $result['success'] );
		self::assertSame( array(), $result['migrated'] );
	}

	public function test_acquired_lock_runs_migrations_and_releases(): void {
		$GLOBALS['lel_test_get_lock_result'] = '1';

		// Versions 1 and 2 are option-only and succeed in the stub environment;
		// version 3 requires dbDelta, which is unavailable here, so the run
		// stops there. That is enough to prove the acquired path proceeds.
		$result = Migrations::run_migrations();

		self::assertSame( array( 1, 2 ), $result['migrated'] );
		self::assertSame( 2, $GLOBALS['lel_test_options']['lel_data_version'] );
		self::assertSame( Advisory_Lock::RELEASED, $result['lock_release_state'] );
		self::assertGreaterThanOrEqual( 1, $GLOBALS['lel_test_release_lock_calls'], 'Lock must be released even when a migration fails.' );
	}

	public function test_current_version_is_noop_success(): void {
		$GLOBALS['lel_test_options']['lel_data_version'] = Migrations::CURRENT_VERSION;
		$GLOBALS['lel_test_get_lock_result']             = '1';

		$result = Migrations::run_migrations();

		self::assertTrue( $result['success'] );
		self::assertSame( array(), $result['migrated'] );
		self::assertSame( Advisory_Lock::RELEASED, $result['lock_release_state'] );
	}

	public function test_version_is_reread_after_lock_acquisition(): void {
		$GLOBALS['lel_test_get_lock_result']       = '1';
		$GLOBALS['lel_test_data_version_on_lock'] = Migrations::CURRENT_VERSION;

		$result = Migrations::run_migrations();

		self::assertTrue( $result['success'] );
		self::assertSame( array(), $result['migrated'], 'A runner must not replay versions completed while it waited for the lock.' );
		self::assertSame( Migrations::CURRENT_VERSION, $GLOBALS['lel_test_options']['lel_data_version'] );
	}

	public function test_failed_version_write_is_not_reported_as_migrated(): void {
		$GLOBALS['lel_test_get_lock_result']          = '1';
		$GLOBALS['lel_test_fail_data_version_update'] = true;

		$result = Migrations::run_migrations();

		self::assertFalse( $result['success'] );
		self::assertSame( array(), $result['migrated'] );
		self::assertSame( 0, $GLOBALS['lel_test_options']['lel_data_version'] );
		self::assertStringContainsString( 'durably confirmed', $result['error'] );
	}

	public function test_complete_postcondition_rejects_a_missing_owned_column(): void {
		$GLOBALS['lel_test_missing_schema_column'] = 'audit_event_id';
		$method = new ReflectionMethod( Migrations::class, 'validate_postconditions' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'audit_event_id column missing' );
		$method->invoke( null, Migrations::CURRENT_VERSION );
	}

	public function test_current_version_still_validates_postconditions_under_lock(): void {
		$GLOBALS['lel_test_options']['lel_data_version'] = Migrations::CURRENT_VERSION;
		$GLOBALS['lel_test_get_lock_result']             = '1';
		$GLOBALS['lel_test_missing_schema_column']       = 'audit_event_id';

		$result = Migrations::run_migrations();

		self::assertFalse( $result['success'] );
		self::assertSame( array(), $result['migrated'] );
		self::assertStringContainsString( 'audit_event_id column missing', $result['error'] );
		self::assertSame( Advisory_Lock::RELEASED, $result['lock_release_state'] );
	}

	public function test_postcondition_checks_are_read_only_and_cover_owned_indexes(): void {
		$GLOBALS['wpdb']->lel_query_log = array();
		$method = new ReflectionMethod( Migrations::class, 'validate_postconditions' );
		$method->invoke( null, Migrations::CURRENT_VERSION );
		$sql = implode( "\n", $GLOBALS['wpdb']->lel_query_log );

		self::assertStringContainsString( 'uniq_open_parent', $sql );
		self::assertStringContainsString( 'dep_parent', $sql );
		self::assertStringContainsString( 'idempotency_key', $sql );
		self::assertDoesNotMatchRegularExpression( '/\b(?:ALTER|CREATE|DROP)\s+TABLE\b/i', $sql );
	}
}
