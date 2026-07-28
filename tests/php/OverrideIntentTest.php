<?php

use Longevity\Core\Audit_Log;
use Longevity\Core\Override_Intent;
use PHPUnit\Framework\TestCase;

final class OverrideIntentTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_override_intents', array() );
		$GLOBALS['lel_test_current_user_id']   = 7;
		$GLOBALS['lel_test_current_user_caps'] = array( 'approve_publication_override' );
		unset( $GLOBALS['lel_test_fail_insert'] );
		Audit_Log::set_test_mode( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_current_user_id'], $GLOBALS['lel_test_current_user_caps'], $GLOBALS['lel_test_fail_insert'] );
		Audit_Log::set_test_mode( true );
	}

	private static function fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'post_id'             => 42,
				'previous_status'     => 'draft',
				'requested_status'    => 'publish',
				'user_id'             => 7,
				'capability_snapshot' => array( 'approve_publication_override' => true ),
				'nonce_verified'      => true,
				'fingerprint'         => 'fp-abc',
				'approval_state'      => 'editorial_current',
				'reason'              => 'Emergency correction',
				'channel'             => 'classic',
				'source_sha'          => str_repeat( 'a', 40 ),
				'plugin_version'      => '3.0.0',
			),
			$overrides
		);
	}

	private static function event_types(): array {
		return array_column( Audit_Log::test_events(), 'event_type' );
	}

	public function test_authorization_is_audited_and_all_immutable_fields_are_stored(): void {
		$state = Override_Intent::authorize( 'correlation-authorize-1', self::fields() );

		self::assertSame( Override_Intent::STATE_AUTHORIZED, $state );
		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_override_intents' );
		self::assertCount( 1, $rows );
		foreach ( array( 'request_id', 'post_id', 'previous_status', 'requested_status', 'user_id', 'capability_snapshot', 'reason', 'fingerprint', 'approval_state', 'source_sha', 'plugin_version', 'requested_at', 'result' ) as $field ) {
			self::assertArrayHasKey( $field, $rows[0] );
		}
		$events = Audit_Log::test_events();
		self::assertSame( 'publication_override_authorized', $events[0]['event_type'] );
		self::assertTrue( $events[0]['mandatory'] );
	}

	public function test_authorization_audit_failure_leaves_failed_non_authoritative_intent(): void {
		Audit_Log::set_test_fail_events( array( 'publication_override_authorized' ) );

		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::authorize( 'correlation-audit-fail-1', self::fields() ) );
		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::intent( 'correlation-audit-fail-1' )['state'] );
	}

	public function test_failed_status_transition_is_durable(): void {
		$correlation = 'correlation-transition-fail-1';
		self::assertSame( Override_Intent::STATE_AUTHORIZED, Override_Intent::authorize( $correlation, self::fields() ) );

		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::finalize( $correlation, 42, 'draft', 'draft' ) );
		$intent = Override_Intent::intent( $correlation );
		self::assertSame( Override_Intent::STATE_FAILED, $intent['state'] );
		self::assertSame( 'status_transition_failed', $intent['result'] );
		self::assertContains( 'publication_override_failed', self::event_types() );
	}

	public function test_success_is_applied_only_after_actual_transition(): void {
		$correlation = 'correlation-applied-1';
		self::assertSame( Override_Intent::STATE_AUTHORIZED, Override_Intent::authorize( $correlation, self::fields() ) );
		self::assertSame( Override_Intent::STATE_AUTHORIZED, Override_Intent::intent( $correlation )['state'] );

		self::assertSame( Override_Intent::STATE_APPLIED, Override_Intent::finalize( $correlation, 42, 'publish', 'draft' ) );
		self::assertSame( Override_Intent::STATE_APPLIED, Override_Intent::intent( $correlation )['state'] );
	}

	public function test_authorization_and_finalization_replays_do_not_duplicate_events(): void {
		$correlation = 'correlation-replay-1';
		self::assertSame( Override_Intent::STATE_AUTHORIZED, Override_Intent::authorize( $correlation, self::fields() ) );
		$after_authorize = count( Audit_Log::test_events() );

		self::assertSame( Override_Intent::STATE_AUTHORIZED, Override_Intent::authorize( $correlation, self::fields() ) );
		self::assertTrue( Override_Intent::authorizes_transition( $correlation, 42 ) );
		self::assertSame( $after_authorize, count( Audit_Log::test_events() ) );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_override_intents' ) );

		self::assertSame( Override_Intent::STATE_APPLIED, Override_Intent::finalize( $correlation, 42, 'publish', 'draft' ) );
		self::assertFalse( Override_Intent::authorizes_transition( $correlation, 42 ) );
		$after_apply = count( Audit_Log::test_events() );
		self::assertSame( Override_Intent::STATE_APPLIED, Override_Intent::authorize( $correlation, self::fields() ) );
		self::assertSame( $after_apply, count( Audit_Log::test_events() ) );
		self::assertSame( Override_Intent::STATE_APPLIED, Override_Intent::finalize( $correlation, 42, 'publish', 'draft' ) );
		self::assertSame( $after_apply, count( Audit_Log::test_events() ) );
	}

	public function test_replay_with_changed_immutable_field_is_denied(): void {
		$correlation = 'correlation-mismatch-1';
		self::assertSame( Override_Intent::STATE_AUTHORIZED, Override_Intent::authorize( $correlation, self::fields() ) );
		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::authorize( $correlation, self::fields( array( 'fingerprint' => 'changed' ) ) ) );
		self::assertSame( Override_Intent::STATE_AUTHORIZED, Override_Intent::intent( $correlation )['state'] );
	}

	public function test_finalization_audit_failure_can_be_compensated(): void {
		$correlation = 'correlation-compensate-1';
		self::assertSame( Override_Intent::STATE_AUTHORIZED, Override_Intent::authorize( $correlation, self::fields() ) );
		Audit_Log::set_test_fail_events( array( 'publication_override_applied' ) );

		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::finalize( $correlation, 42, 'publish', 'draft' ) );
		self::assertSame( Override_Intent::STATE_COMPENSATED, Override_Intent::compensate( $correlation, 42, 'draft' ) );
		self::assertSame( Override_Intent::STATE_COMPENSATED, Override_Intent::intent( $correlation )['state'] );
		self::assertFalse( Override_Intent::authorizes_transition( $correlation, 42 ) );
	}

	public function test_invalid_authorization_inputs_fail_closed(): void {
		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::authorize( 'short', self::fields() ) );
		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::authorize( 'correlation-no-cap-1', self::fields( array( 'capability_snapshot' => array() ) ) ) );
		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::authorize( 'correlation-no-reason-1', self::fields( array( 'reason' => 'short' ) ) ) );
		self::assertSame( Override_Intent::STATE_FAILED, Override_Intent::authorize( 'correlation-no-nonce-1', self::fields( array( 'nonce_verified' => false ) ) ) );
	}
}
