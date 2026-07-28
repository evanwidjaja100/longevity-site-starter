<?php

use Longevity\Core\Evidence_Store;
use Longevity\Core\Audit_Log;
use Longevity\Core\System_Readiness;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string {
		return (string) ( $GLOBALS['lel_test_environment'] ?? 'local' );
	}
}
if ( ! defined( 'LEL_RELEASE_SHA' ) ) {
	define( 'LEL_RELEASE_SHA', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );
}
if ( ! defined( 'LEL_RELEASE_ARTIFACT_SHA256' ) ) {
	define( 'LEL_RELEASE_ARTIFACT_SHA256', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' );
}

final class EvidenceStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_external_evidence', array() );
		$GLOBALS['lel_test_options']         = array();
		$GLOBALS['lel_test_current_user_id'] = 7;
		$GLOBALS['lel_test_environment']     = 'local';
		Audit_Log::set_test_mode( true );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_external_evidence', array() );
		$GLOBALS['lel_test_options'] = array();
		unset( $GLOBALS['lel_test_fail_evidence_cleanup'] );
		Audit_Log::set_test_mode( false );
	}

	public function test_store_is_append_only_by_api(): void {
		self::assertFalse( method_exists( Evidence_Store::class, 'update' ) );
		self::assertFalse( method_exists( Evidence_Store::class, 'delete' ) );
	}

	public function test_release_scoped_type_requires_valid_sha_and_checksum(): void {
		self::assertInstanceOf( WP_Error::class, Evidence_Store::record( 'release-artifact', array( 'result' => 'pass' ) ) );
		self::assertInstanceOf( WP_Error::class, Evidence_Store::record( 'release-artifact', array( 'result' => 'pass', 'release_sha' => 'abc123', 'artifact_checksum' => 'deadbeef' ) ) );

		$id = Evidence_Store::record(
			'release-artifact',
			array( 'result' => 'pass', 'release_sha' => str_repeat( 'a', 40 ), 'artifact_checksum' => str_repeat( 'b', 64 ) )
		);
		self::assertIsInt( $id );
	}

	public function test_unknown_type_result_environment_and_missing_expiry_are_rejected(): void {
		self::assertInstanceOf( WP_Error::class, Evidence_Store::record( 'made-up', array( 'result' => 'pass' ) ) );
		self::assertInstanceOf( WP_Error::class, Evidence_Store::record( 'backup', $this->backup_fields( array( 'result' => 'maybe' ) ) ) );
		self::assertInstanceOf( WP_Error::class, Evidence_Store::record( 'backup', $this->backup_fields( array( 'environment' => 'preview' ) ) ) );
		self::assertInstanceOf( WP_Error::class, Evidence_Store::record( 'backup', array( 'result' => 'ok', 'environment' => 'local' ) ) );
	}

	public function test_operational_type_records_without_release_identity(): void {
		$id = Evidence_Store::record( 'backup', $this->backup_fields( array( 'artifact_ref' => 's3://b/x.sql.gz' ) ) );
		self::assertIsInt( $id );
		$row = Evidence_Store::get( $id );
		self::assertSame( 'backup', $row['evidence_type'] );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $row['record_hash'] );
	}

	public function test_store_itself_creates_mandatory_audit_link(): void {
		$id     = Evidence_Store::record( 'backup', $this->backup_fields() );
		$events = Audit_Log::test_events();

		self::assertIsInt( $id );
		self::assertCount( 1, $events );
		self::assertSame( 'evidence_recorded', $events[0]['event_type'] );
		self::assertSame( 'external_evidence', $events[0]['object_type'] );
		self::assertSame( $id, $events[0]['object_id'] );
		self::assertTrue( $events[0]['mandatory'] );
	}

	public function test_audit_failure_refuses_and_removes_unlinked_evidence(): void {
		Audit_Log::set_test_fail_events( array( 'evidence_recorded' ) );
		$result = Evidence_Store::record( 'backup', $this->backup_fields() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'evidence_audit_failed', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_external_evidence' ) );
	}

	public function test_unlinked_row_cannot_verify_even_if_cleanup_also_fails(): void {
		Audit_Log::set_test_fail_events( array( 'evidence_recorded' ) );
		$GLOBALS['lel_test_fail_evidence_cleanup'] = true;
		$result = Evidence_Store::record( 'backup', $this->backup_fields() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_external_evidence' ) );
		self::assertFalse( Evidence_Store::verify( 1 ) );
		self::assertSame( 'error', System_Readiness::report()['checks']['last_backup']['status'] );
	}

	public function test_verify_and_readiness_detect_record_tampering(): void {
		$id = Evidence_Store::record( 'backup', $this->backup_fields() );
		self::assertTrue( Evidence_Store::verify( $id ) );

		$rows              = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_external_evidence' );
		$rows[0]['result'] = 'fail';
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_external_evidence', $rows );
		self::assertFalse( Evidence_Store::verify( $id ) );
		self::assertSame( 'error', System_Readiness::report()['checks']['last_backup']['status'] );
	}

	public function test_latest_returns_newest_record_for_type(): void {
		Evidence_Store::record( 'backup', $this->backup_fields( array( 'artifact_ref' => 'first' ) ) );
		$second = Evidence_Store::record( 'backup', $this->backup_fields( array( 'artifact_ref' => 'second' ) ) );
		self::assertSame( $second, (int) Evidence_Store::latest( 'backup' )['id'] );
	}

	public function test_readiness_requires_success_current_environment_and_unexpired_record(): void {
		Evidence_Store::record( 'backup', $this->backup_fields() );
		self::assertSame( 'ok', System_Readiness::report()['checks']['last_backup']['status'] );

		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_external_evidence', array() );
		Evidence_Store::record( 'backup', $this->backup_fields( array( 'result' => 'fail' ) ) );
		self::assertSame( 'blocked', System_Readiness::report()['checks']['last_backup']['status'] );

		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_external_evidence', array() );
		Evidence_Store::record( 'backup', $this->backup_fields( array( 'environment' => 'staging' ) ) );
		self::assertSame( 'blocked', System_Readiness::report()['checks']['last_backup']['status'] );

		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_external_evidence', array() );
		Evidence_Store::record( 'backup', $this->backup_fields( array( 'produced_at' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ) );
		self::assertSame( 'blocked', System_Readiness::report()['checks']['last_backup']['status'] );
	}

	public function test_release_readiness_requires_exact_deployed_identity(): void {
		Evidence_Store::record( 'release-artifact', array( 'result' => 'pass', 'environment' => 'local', 'release_sha' => str_repeat( 'c', 40 ), 'artifact_checksum' => str_repeat( 'd', 64 ) ) );
		self::assertSame( 'blocked', System_Readiness::report()['checks']['release_evidence']['status'] );

		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_external_evidence', array() );
		Evidence_Store::record( 'release-artifact', array( 'result' => 'pass', 'environment' => 'local', 'release_sha' => LEL_RELEASE_SHA, 'artifact_checksum' => LEL_RELEASE_ARTIFACT_SHA256 ) );
		self::assertSame( 'ok', System_Readiness::report()['checks']['release_evidence']['status'] );
	}

	public function test_readiness_uses_newest_valid_matching_record_not_newest_mismatch(): void {
		$matching = Evidence_Store::record( 'backup', $this->backup_fields() );
		Evidence_Store::record( 'backup', $this->backup_fields( array( 'environment' => 'staging', 'result' => 'fail' ) ) );
		self::assertSame( $matching, (int) Evidence_Store::latest_valid_matching( 'backup', 'local' )['id'] );
		self::assertSame( 'ok', System_Readiness::report()['checks']['last_backup']['status'] );

		Evidence_Store::record( 'release-artifact', array( 'result' => 'pass', 'environment' => 'local', 'release_sha' => LEL_RELEASE_SHA, 'artifact_checksum' => LEL_RELEASE_ARTIFACT_SHA256 ) );
		Evidence_Store::record( 'release-artifact', array( 'result' => 'fail', 'environment' => 'local', 'release_sha' => str_repeat( 'c', 40 ), 'artifact_checksum' => str_repeat( 'd', 64 ) ) );
		self::assertSame( 'ok', System_Readiness::report()['checks']['release_evidence']['status'] );
	}

	public function test_supersession_is_validated_and_visible(): void {
		$first = Evidence_Store::record( 'backup', $this->backup_fields() );
		self::assertIsInt( $first );
		$second = Evidence_Store::record( 'backup', $this->backup_fields( array( 'supersedes_id' => $first ) ) );
		self::assertIsInt( $second );
		self::assertTrue( Evidence_Store::is_superseded( $first ) );
		self::assertTrue( Evidence_Store::verify( $second ) );
		self::assertInstanceOf( WP_Error::class, Evidence_Store::record( 'restore', $this->backup_fields( array( 'supersedes_id' => $second ) ) ) );
	}

	public function test_attachment_hash_is_reverified(): void {
		$file = tempnam( sys_get_temp_dir(), 'lel-evidence-' );
		self::assertIsString( $file );
		file_put_contents( $file, 'evidence' );
		try {
			$id = Evidence_Store::record( 'backup', $this->backup_fields( array( 'attachment_location' => $file, 'attachment_sha256' => hash_file( 'sha256', $file ) ) ) );
			self::assertIsInt( $id );
			self::assertTrue( Evidence_Store::verify( $id ) );
			file_put_contents( $file, 'changed' );
			self::assertFalse( Evidence_Store::verify( $id ) );
		} finally {
			@unlink( $file );
		}
	}

	public function test_legacy_option_never_satisfies_release_scope(): void {
		$report = System_Readiness::report();
		self::assertArrayHasKey( 'release_evidence', $report['checks'] );
		self::assertNotSame( 'ok', $report['checks']['release_evidence']['status'] );
	}

	public function test_migration_14_owns_the_evidence_table(): void {
		self::assertGreaterThanOrEqual( 14, \Longevity\Core\Migrations::CURRENT_VERSION );
	}

	private function backup_fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'result'      => 'ok',
				'environment' => 'local',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			),
			$overrides
		);
	}
}
