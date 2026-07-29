<?php

use Longevity\Core\Audit_Log;
use Longevity\Core\Legal_Hold;
use Longevity\Core\Public_Contact;
use Longevity\Core\Roles;
use PHPUnit\Framework\TestCase;

final class LegalHoldTest extends TestCase {
	private const HOLDER = 42;
	private const NOBODY = 43;

	protected function setUp(): void {
		Audit_Log::set_test_mode( true );
		$GLOBALS['lel_test_posts']         = array();
		$GLOBALS['lel_test_meta']          = array();
		$GLOBALS['lel_test_deleted_posts'] = array();
		$GLOBALS['lel_test_user_caps']     = array(
			self::HOLDER => array( Legal_Hold::CAPABILITY ),
			self::NOBODY => array( 'read' ),
		);
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_rate_limits', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_notification_outbox', array() );
		unset( $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_release_lock_calls'] );
	}

	protected function tearDown(): void {
		Audit_Log::set_test_mode( true );
		$GLOBALS['lel_test_user_caps'] = array();
		unset( $GLOBALS['lel_test_scheduled'], $GLOBALS['lel_test_options']['lel_contact_retention_days'], $GLOBALS['lel_test_fail_meta_updates'], $GLOBALS['lel_test_fail_meta_deletes'], $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_release_lock_calls'] );
	}

	private function seed_message( int $id, string $post_date_gmt = '2020-01-01 00:00:00' ): void {
		$post                = new WP_Post();
		$post->ID            = $id;
		$post->post_type     = 'longevity_message';
		$post->post_status   = 'private';
		$post->post_date_gmt = $post_date_gmt;
		$GLOBALS['lel_test_posts'][ $id ] = $post;
		// Retention selection is driven by the authoritative per-record deadline;
		// seed a long-past canonical value so these fixtures are due for cleanup.
		$GLOBALS['lel_test_meta'][ $id ]['contact_retention_until_gmt'] = '2020-01-02 00:00:00';
	}

	public function test_place_requires_capability(): void {
		$this->seed_message( 10 );
		$result = Legal_Hold::place( 10, self::NOBODY, 'investigation', 'CASE-1' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( '', (string) get_post_meta( 10, Legal_Hold::META_KEY, true ) );
		self::assertSame( array(), Audit_Log::test_events() );
	}

	public function test_place_requires_reason(): void {
		$this->seed_message( 10 );
		$result = Legal_Hold::place( 10, self::HOLDER, '   ', 'CASE-1' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( '', (string) get_post_meta( 10, Legal_Hold::META_KEY, true ) );
	}

	public function test_place_rejects_non_message_posts(): void {
		$post            = new WP_Post();
		$post->ID        = 11;
		$post->post_type = 'post';
		$GLOBALS['lel_test_posts'][11] = $post;
		self::assertInstanceOf( WP_Error::class, Legal_Hold::place( 11, self::HOLDER, 'reason', 'CASE-1' ) );
	}

	public function test_place_requires_case_reference(): void {
		$this->seed_message( 10 );
		$result = Legal_Hold::place( 10, self::HOLDER, 'investigation' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'lel_hold_case_required', $result->get_error_code() );
		self::assertFalse( Legal_Hold::is_held( 10 ) );
	}

	public function test_place_sets_active_state_with_audit(): void {
		$this->seed_message( 10 );
		self::assertTrue( Legal_Hold::place( 10, self::HOLDER, 'regulator request', 'CASE-9' ) );
		self::assertSame( Legal_Hold::STATE_ACTIVE, (string) get_post_meta( 10, Legal_Hold::META_KEY, true ) );
		self::assertTrue( Legal_Hold::is_held( 10 ) );
		self::assertSame( 'CASE-9', get_post_meta( 10, Legal_Hold::CASE_META_KEY, true ) );
		self::assertSame( (string) self::HOLDER, get_post_meta( 10, Legal_Hold::ACTOR_META_KEY, true ) );
		self::assertNotSame( '', get_post_meta( 10, Legal_Hold::PLACED_AT_META_KEY, true ) );
		self::assertTrue( DateTimeImmutable::createFromFormat( '!Y-m-d', (string) get_post_meta( 10, Legal_Hold::REVIEW_AT_META_KEY, true ) ) instanceof DateTimeImmutable );

		$events = Audit_Log::test_events();
		self::assertSame( array( 'legal_hold_requested', 'legal_hold_placed' ), array_column( $events, 'event_type' ) );
		self::assertTrue( $events[1]['mandatory'], 'Legal hold audit must be mandatory (fail-closed).' );
		self::assertSame( 'CASE-9', $events[1]['payload']['case_ref'] );
	}

	public function test_hold_transition_refuses_when_contact_lock_is_contended(): void {
		$this->seed_message( 10 );
		$GLOBALS['lel_test_get_lock_result'] = '0';
		$result = Legal_Hold::place( 10, self::HOLDER, 'reason', 'CASE-10' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'lel_hold_lock_unavailable', $result->get_error_code() );
		self::assertSame( '', Legal_Hold::state( 10 ) );
		self::assertSame( 0, (int) ( $GLOBALS['lel_test_release_lock_calls'] ?? 0 ) );
	}

	public function test_place_audit_failure_prevents_state_change(): void {
		$this->seed_message( 10 );
		Audit_Log::set_test_fail_events( array( 'legal_hold_requested' ) );
		$result = Legal_Hold::place( 10, self::HOLDER, 'reason', 'CASE-1' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( '', (string) get_post_meta( 10, Legal_Hold::META_KEY, true ) );
		self::assertFalse( Legal_Hold::is_held( 10 ) );
	}

	public function test_place_state_write_failure_never_records_placed(): void {
		$this->seed_message( 10 );
		$GLOBALS['lel_test_fail_meta_updates'] = array( Legal_Hold::META_KEY );
		self::assertInstanceOf( WP_Error::class, Legal_Hold::place( 10, self::HOLDER, 'reason', 'CASE-1' ) );
		self::assertSame( '', Legal_Hold::state( 10 ) );
		self::assertNotContains( 'legal_hold_placed', array_column( Audit_Log::test_events(), 'event_type' ) );
	}

	public function test_release_flips_state_with_audit_and_fails_closed(): void {
		$this->seed_message( 10 );
		self::assertTrue( Legal_Hold::place( 10, self::HOLDER, 'reason', 'CASE-9' ) );

		Audit_Log::set_test_fail_events( array( 'legal_hold_release_requested' ) );
		self::assertInstanceOf( WP_Error::class, Legal_Hold::release( 10, self::HOLDER, 'closed' ) );
		self::assertSame( Legal_Hold::STATE_ACTIVE, (string) get_post_meta( 10, Legal_Hold::META_KEY, true ) );

		Audit_Log::set_test_fail_events( array() );
		self::assertTrue( Legal_Hold::release( 10, self::HOLDER, 'closed', 'CASE-9' ) );
		self::assertSame( Legal_Hold::STATE_RELEASED, (string) get_post_meta( 10, Legal_Hold::META_KEY, true ) );
		self::assertFalse( Legal_Hold::is_held( 10 ) );
		self::assertSame( (string) self::HOLDER, get_post_meta( 10, Legal_Hold::RELEASE_ACTOR_META_KEY, true ) );
		self::assertSame( 'closed', get_post_meta( 10, Legal_Hold::RELEASE_REASON_META_KEY, true ) );
		self::assertNotSame( '', get_post_meta( 10, Legal_Hold::RELEASED_AT_META_KEY, true ) );
	}

	public function test_release_state_write_failure_keeps_active_hold(): void {
		$this->seed_message( 10 );
		self::assertTrue( Legal_Hold::place( 10, self::HOLDER, 'reason', 'CASE-1' ) );
		Audit_Log::reset_test_events();
		$GLOBALS['lel_test_fail_meta_updates'] = array( Legal_Hold::META_KEY );
		self::assertInstanceOf( WP_Error::class, Legal_Hold::release( 10, self::HOLDER, 'closed' ) );
		self::assertSame( Legal_Hold::STATE_ACTIVE, Legal_Hold::state( 10 ) );
		self::assertNotContains( 'legal_hold_released', array_column( Audit_Log::test_events(), 'event_type' ) );
	}

	public function test_release_requires_active_hold(): void {
		$this->seed_message( 10 );
		self::assertInstanceOf( WP_Error::class, Legal_Hold::release( 10, self::HOLDER, 'nothing to release', 'CASE-1' ) );
	}

	public function test_retention_exempts_only_active_holds(): void {
		$this->seed_message( 10 );
		$this->seed_message( 11 );
		$this->seed_message( 12 );
		self::assertTrue( Legal_Hold::place( 10, self::HOLDER, 'hold this', 'CASE-10' ) );
		self::assertTrue( Legal_Hold::place( 12, self::HOLDER, 'hold then release', 'CASE-12' ) );
		self::assertTrue( Legal_Hold::release( 12, self::HOLDER, 'done' ) );

		$query_start = count( $GLOBALS['wpdb']->lel_query_log );
		Public_Contact::run_retention_cleanup();

		self::assertArrayHasKey( 10, $GLOBALS['lel_test_posts'], 'Active hold must survive retention.' );
		self::assertArrayNotHasKey( 11, $GLOBALS['lel_test_posts'], 'Unheld expired record must be deleted.' );
		self::assertArrayNotHasKey( 12, $GLOBALS['lel_test_posts'], 'Released hold must be deletable again.' );
		$queries = implode( "\n", array_slice( $GLOBALS['wpdb']->lel_query_log, $query_start ) );
		self::assertStringNotContainsString( 'object_id = 10', $queries, 'Active holds keep their PII-bearing notifications untouched.' );
		self::assertStringContainsString( 'object_id = 11', $queries, 'Retention removes notifications before deleting the message.' );
		self::assertStringContainsString( 'object_id = 12', $queries, 'Released holds no longer prevent notification cleanup.' );
	}

	public function test_legacy_false_is_deletable_but_ambiguous_value_is_retained_until_explicit_resolution(): void {
		$this->seed_message( 20 );
		$this->seed_message( 21 );
		update_post_meta( 20, Legal_Hold::LEGACY_META_KEY, 'released' );
		update_post_meta( 21, Legal_Hold::LEGACY_META_KEY, 'unknown-old-value' );

		self::assertFalse( Legal_Hold::is_held( 20 ), 'A released legacy value is never active.' );
		self::assertFalse( Legal_Hold::is_held( 21 ), 'Ambiguous legacy metadata is quarantined for review, not promoted to an active hold.' );
		$report = Legal_Hold::legacy_report();
		self::assertSame( array( 20, 21 ), $report['post_ids'] );
		self::assertSame( array( 20 ), $report['non_active'] );
		self::assertSame( array( 21 ), $report['quarantined'] );

		Public_Contact::run_retention_cleanup();
		self::assertArrayNotHasKey( 20, $GLOBALS['lel_test_posts'] );
		self::assertArrayHasKey( 21, $GLOBALS['lel_test_posts'], 'Ambiguous legacy holds must remain quarantined.' );

		self::assertTrue( Legal_Hold::place( 21, self::HOLDER, 'privacy-owner resolution', 'CASE-21' ) );
		self::assertTrue( Legal_Hold::release( 21, self::HOLDER, 'legacy state resolved', 'CASE-21' ) );
		Public_Contact::run_retention_cleanup();
		self::assertArrayNotHasKey( 21, $GLOBALS['lel_test_posts'] );
	}

	public function test_retention_skips_contact_when_advisory_lock_is_contended(): void {
		$this->seed_message( 40 );
		$GLOBALS['lel_test_get_lock_result'] = '0';
		$query_start = count( $GLOBALS['wpdb']->lel_query_log );
		self::assertSame( 0, Public_Contact::run_retention_cleanup() );
		self::assertArrayHasKey( 40, $GLOBALS['lel_test_posts'] );
		$queries = implode( "\n", array_slice( $GLOBALS['wpdb']->lel_query_log, $query_start ) );
		self::assertStringContainsString( Legal_Hold::lock_name( 40 ), $queries );
		self::assertStringNotContainsString( 'object_id = 40', $queries );
	}

	public function test_malformed_or_overdue_active_state_does_not_retain(): void {
		$this->seed_message( 30 );
		$this->seed_message( 31 );
		update_post_meta( 30, Legal_Hold::META_KEY, Legal_Hold::STATE_ACTIVE );
		self::assertFalse( Legal_Hold::is_held( 30 ), 'A bare active string is not an authoritative hold.' );

		self::assertTrue( Legal_Hold::place( 31, self::HOLDER, 'review required', 'CASE-31' ) );
		update_post_meta( 31, Legal_Hold::REVIEW_AT_META_KEY, '2020-01-01' );
		self::assertFalse( Legal_Hold::is_held( 31 ), 'An overdue review cannot silently retain contact data.' );

		Public_Contact::run_retention_cleanup();
		self::assertArrayNotHasKey( 30, $GLOBALS['lel_test_posts'] );
		self::assertArrayNotHasKey( 31, $GLOBALS['lel_test_posts'] );
	}

	public function test_roles_matrix_grants_capability_to_administrators_only(): void {
		self::assertSame( '2.2.0', Roles::MATRIX_VERSION );
		$GLOBALS['lel_test_roles'] = array(
			'administrator' => new WP_Role( 'administrator' ),
			'editor'        => new WP_Role( 'editor' ),
		);
		Roles::register();
		self::assertTrue( $GLOBALS['lel_test_roles']['administrator']->has_cap( Legal_Hold::CAPABILITY ) );
		self::assertFalse( $GLOBALS['lel_test_roles']['editor']->has_cap( Legal_Hold::CAPABILITY ) );
		unset( $GLOBALS['lel_test_roles'] );
	}
}
