<?php
/**
 * Reviewer credential lifecycle and cascade invalidation tests.
 *
 * @package LongevityCore
 */

use Longevity\Core\Approval_Service;
use Longevity\Core\Audit_Log;
use Longevity\Core\Dependency_Index;
use Longevity\Core\Reviewer_Credentials;
use PHPUnit\Framework\TestCase;

final class ReviewerCredentialCascadeTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_invalidation_queue', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_dependencies', array() );
		$GLOBALS['wpdb']->lel_query_log = array();
		$GLOBALS['wpdb']->last_error    = '';
		$GLOBALS['lel_test_options']    = array();
		$GLOBALS['lel_test_meta']       = array();
		$GLOBALS['lel_test_user_meta']  = array();
		$GLOBALS['lel_test_user_caps']  = array();
		$GLOBALS['lel_test_scheduled']  = array();
		Audit_Log::set_test_mode( true );
		Audit_Log::reset_test_events();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_user_caps'], $GLOBALS['lel_test_user_meta'] );
		Audit_Log::set_test_mode( true );
	}

	/** Open invalidation-queue rows targeting a given parent post. */
	private static function open_rows_for( int $parent ): array {
		return array_values(
			array_filter(
				$GLOBALS['wpdb']->lel_test_rows( 'wp_lel_invalidation_queue' ),
				static fn( array $row ): bool => (int) ( $row['parent_post_id'] ?? 0 ) === $parent && ! empty( $row['open_marker'] )
			)
		);
	}

	/** Count of queue INSERT statements issued so far. */
	private static function queue_inserts(): int {
		return count(
			array_filter(
				$GLOBALS['wpdb']->lel_query_log,
				static fn( string $sql ): bool => (bool) preg_match( '/INSERT INTO wp_lel_invalidation_queue/i', $sql )
			)
		);
	}

	/** Seed a complete verified credential snapshot for a reviewer. */
	private static function seed_verified( int $reviewer_id, string $expiration ): void {
		$GLOBALS['lel_test_user_caps'][ $reviewer_id ] = array( 'complete_medical_review' );
		$GLOBALS['lel_test_user_meta'][ $reviewer_id ] = array(
			'credential_verification_status'       => 'verified',
			'credential_verification_date'         => '2026-01-01',
			'credential_expiration_date'           => $expiration,
			'credential_verified_by_user_id'       => 3,
			'credential_verification_evidence_ref' => 'ticket-123',
			'verified_professional_credentials'    => 'MD',
			'verified_review_scope'                => 'safety_only, full_article',
			'verified_jurisdictions'               => 'US, Global',
			'credential_verification_version'      => Reviewer_Credentials::VERSION,
		);
	}

	public function test_verified_scope_change_cascades_to_dependent_content(): void {
		self::seed_verified( 71, '2027-01-01' );
		Dependency_Index::register( 'credential', 71, 900 );

		// A material narrowing of the verified review scope: not the status or
		// verifier field, but still governance-material.
		$GLOBALS['lel_test_user_meta'][71]['verified_review_scope'] = 'safety_only';
		Approval_Service::on_credential_changed( 0, 71, 'verified_review_scope', 'safety_only' );

		self::assertCount( 1, self::open_rows_for( 900 ), 'A verified-scope change must invalidate dependent approvals.' );
	}

	public function test_expiration_sweep_marks_expired_and_cascades(): void {
		self::seed_verified( 72, '2026-06-01' );
		Dependency_Index::register( 'credential', 72, 901 );

		$report = Reviewer_Credentials::run_expiration_sweep( '2026-07-28', 50 );

		self::assertSame( 1, $report['expired'], 'One overdue verified credential must be swept.' );
		self::assertSame( 'expired', $GLOBALS['lel_test_user_meta'][72]['credential_verification_status'] );
		self::assertCount( 1, self::open_rows_for( 901 ), 'Expiring a credential must invalidate dependent approvals.' );
		self::assertContains( 'credential_expired', array_column( Audit_Log::test_events(), 'event_type' ), 'Expiration must be durably audited.' );
	}

	public function test_expiration_sweep_ignores_current_credentials(): void {
		self::seed_verified( 73, '2027-01-01' );
		Dependency_Index::register( 'credential', 73, 902 );

		$report = Reviewer_Credentials::run_expiration_sweep( '2026-07-28', 50 );

		self::assertSame( 0, $report['expired'], 'A credential valid as-of the sweep date must not be expired.' );
		self::assertSame( 'verified', $GLOBALS['lel_test_user_meta'][73]['credential_verification_status'] );
		self::assertCount( 0, self::open_rows_for( 902 ) );
	}

	public function test_expiration_sweep_is_idempotent(): void {
		self::seed_verified( 74, '2026-06-01' );
		Dependency_Index::register( 'credential', 74, 903 );

		Reviewer_Credentials::run_expiration_sweep( '2026-07-28', 50 );
		$second = Reviewer_Credentials::run_expiration_sweep( '2026-07-28', 50 );

		self::assertSame( 0, $second['expired'], 'An already-expired credential must not be swept twice.' );
	}

	public function test_unchanged_snapshot_coalesces_duplicate_callbacks(): void {
		self::seed_verified( 75, '2027-01-01' );
		Dependency_Index::register( 'credential', 75, 904 );

		// Two separate verified-field callbacks over an unchanged snapshot: the
		// first enqueues one cascade, the second must be a fingerprint no-op.
		Approval_Service::on_credential_changed( 0, 75, 'verified_review_scope', 'safety_only, full_article' );
		$after_first = self::queue_inserts();
		Approval_Service::on_credential_changed( 0, 75, 'verified_jurisdictions', 'US, Global' );
		$after_second = self::queue_inserts();

		self::assertSame( 1, $after_first, 'First material change enqueues exactly one cascade.' );
		self::assertSame( $after_first, $after_second, 'A callback over an unchanged snapshot must not re-enqueue.' );
	}
}
