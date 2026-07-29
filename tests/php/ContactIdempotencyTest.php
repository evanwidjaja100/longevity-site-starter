<?php

use Longevity\Core\Contact_Idempotency;
use Longevity\Core\Public_Contact;
use Longevity\Core\System_Readiness;
use PHPUnit\Framework\TestCase;

/**
 * PR-08: the contact idempotency reservation must be an atomic persistence
 * boundary so two identical requests create at most one accepted aggregate and
 * one active notification, without ever leaking PII into the reservation table.
 */
final class ContactIdempotencyTest extends TestCase {
	private const UUID = 'a2345678-1234-4123-8123-123456789abc';

	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_contact_idempotency', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_rate_limits', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_notification_outbox', array() );
		$GLOBALS['lel_test_posts']         = array();
		$GLOBALS['lel_test_meta']          = array();
		$GLOBALS['lel_test_mails']         = array();
		$GLOBALS['lel_test_redirects']     = array();
		$GLOBALS['lel_test_deleted_posts'] = array();
		$GLOBALS['lel_test_has_action']    = array();
		$GLOBALS['lel_test_options']['admin_email'] = 'admin@example.com';
		$GLOBALS['wpdb']->last_error = '';
		unset( $GLOBALS['lel_test_wp_die'], $GLOBALS['lel_test_fail_insert'], $GLOBALS['lel_test_fail_idem_insert'], $GLOBALS['lel_test_fail_idem_update'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.30';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_contact_idempotency', array() );
		unset( $GLOBALS['lel_test_wp_die'], $GLOBALS['lel_test_fail_insert'], $GLOBALS['lel_test_fail_idem_insert'], $GLOBALS['lel_test_fail_idem_update'] );
		$_POST = array();
	}

	/** @return array<string, string> */
	private function submission( string $uuid ): array {
		return array(
			'_wpnonce'                     => 'test_nonce_longevity_contact',
			'longevity_website'            => '',
			'longevity_contact_name'       => 'Jane Doe',
			'longevity_contact_email'      => 'jane@example.com',
			'longevity_contact_subject'    => 'general',
			'longevity_contact_message'    => 'Hello there.',
			'longevity_contact_request_id' => $uuid,
		);
	}

	private function expire_lease( string $key, int $offset = -120 ): void {
		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_contact_idempotency' );
		foreach ( $rows as &$row ) {
			if ( (string) $row['request_key_hash'] === $key ) {
				$row['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + $offset );
			}
		}
		unset( $row );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_contact_idempotency', $rows );
	}

	public function test_reserve_is_atomic_single_owner(): void {
		$key   = Contact_Idempotency::key_hash( self::UUID );
		$first = Contact_Idempotency::reserve( $key );
		self::assertSame( 'reserved', $first['state'] );

		$second = Contact_Idempotency::reserve( $key );
		self::assertSame( 'in_progress', $second['state'], 'A fresh live reservation must block a second concurrent request.' );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_contact_idempotency' ), 'The unique key must permit only one reservation row.' );
	}

	public function test_completed_reservation_returns_completed(): void {
		$key = Contact_Idempotency::key_hash( self::UUID );
		Contact_Idempotency::reserve( $key );
		self::assertTrue( Contact_Idempotency::link_post( $key, 55 ) );
		self::assertTrue( Contact_Idempotency::complete( $key, 55 ) );

		$again = Contact_Idempotency::reserve( $key );
		self::assertSame( 'completed', $again['state'] );
		self::assertSame( 55, (int) $again['post_id'] );
	}

	public function test_expired_processing_without_post_is_reclaimable(): void {
		$key = Contact_Idempotency::key_hash( self::UUID );
		Contact_Idempotency::reserve( $key );
		$this->expire_lease( $key );

		$again = Contact_Idempotency::reserve( $key );
		self::assertSame( 'reclaimed', $again['state'] );
		self::assertSame( 0, (int) $again['post_id'] );
	}

	public function test_expired_processing_with_linked_post_resumes(): void {
		$key = Contact_Idempotency::key_hash( self::UUID );
		Contact_Idempotency::reserve( $key );
		Contact_Idempotency::link_post( $key, 77 );
		$this->expire_lease( $key );

		$again = Contact_Idempotency::reserve( $key );
		self::assertSame( 'resume', $again['state'], 'A crash after aggregate creation must reconcile, not duplicate.' );
		self::assertSame( 77, (int) $again['post_id'] );
	}

	public function test_failed_reservation_is_reclaimable_without_post(): void {
		$key = Contact_Idempotency::key_hash( self::UUID );
		Contact_Idempotency::reserve( $key );
		Contact_Idempotency::link_post( $key, 88 );
		self::assertTrue( Contact_Idempotency::mark_failed( $key ) );

		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_contact_idempotency' );
		self::assertNull( $rows[0]['message_post_id'], 'A failed reservation must drop its post linkage.' );

		$again = Contact_Idempotency::reserve( $key );
		self::assertSame( 'reclaimed', $again['state'] );
	}

	public function test_reserve_unavailable_when_table_missing(): void {
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_contact_idempotency' );
		$res = Contact_Idempotency::reserve( Contact_Idempotency::key_hash( self::UUID ) );
		self::assertSame( 'unavailable', $res['state'] );
	}

	public function test_reserve_unavailable_on_db_error(): void {
		$GLOBALS['lel_test_fail_idem_insert'] = true;
		$res = Contact_Idempotency::reserve( Contact_Idempotency::key_hash( self::UUID ) );
		self::assertSame( 'unavailable', $res['state'], 'A DB error must never masquerade as a successful reservation.' );
	}

	public function test_stats_report_processing_and_stuck(): void {
		$key = Contact_Idempotency::key_hash( self::UUID );
		Contact_Idempotency::reserve( $key );

		$stats = Contact_Idempotency::stats();
		self::assertTrue( $stats['available'] );
		self::assertSame( 1, (int) $stats['processing'] );
		self::assertSame( 0, (int) $stats['stuck'] );

		$this->expire_lease( $key, -4000 );
		$stats = Contact_Idempotency::stats();
		self::assertGreaterThanOrEqual( 1, (int) $stats['stuck'], 'A long-expired processing reservation must be observable as stuck.' );
	}

	public function test_reservation_row_contains_no_pii(): void {
		$_POST = $this->submission( self::UUID );
		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Accepted submission should redirect.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_redirect', $e->getMessage() );
		}
		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_contact_idempotency' );
		self::assertCount( 1, $rows );
		$blob = strtolower( (string) wp_json_encode( $rows[0] ) );
		self::assertStringNotContainsString( 'jane', $blob );
		self::assertStringNotContainsString( 'example.com', $blob );
		self::assertStringNotContainsString( 'hello there', $blob );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $rows[0]['request_key_hash'] );
		self::assertSame( 'completed', $rows[0]['state'] );
	}

	public function test_duplicate_submission_creates_single_aggregate_and_notification(): void {
		for ( $i = 0; $i < 2; $i++ ) {
			$_POST = $this->submission( self::UUID );
			try {
				Public_Contact::handle_contact_submission();
				self::fail( 'Submission should redirect.' );
			} catch ( \RuntimeException $e ) {
				self::assertSame( 'lel_test_redirect', $e->getMessage() );
			}
		}
		self::assertCount( 1, $GLOBALS['lel_test_posts'], 'Two identical requests must create exactly one aggregate.' );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' ), 'Two identical requests must create exactly one notification.' );
		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_contact_idempotency' );
		self::assertCount( 1, $rows );
		self::assertSame( 'completed', $rows[0]['state'] );
	}

	public function test_malformed_uuid_creates_no_reservation(): void {
		$_POST = $this->submission( 'not-a-valid-uuid' );
		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'A malformed request id should redirect with an error.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_redirect', $e->getMessage() );
		}
		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_contact_idempotency' ) );
		self::assertSame( array(), $GLOBALS['lel_test_posts'] );
	}

	public function test_submission_fails_closed_when_reservation_unavailable(): void {
		$GLOBALS['lel_test_fail_idem_insert'] = true;
		$_POST = $this->submission( self::UUID );
		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'An unavailable reservation boundary must fail closed.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_wp_die', $e->getMessage() );
		}
		self::assertSame( 503, (int) ( $GLOBALS['lel_test_wp_die']['args']['response'] ?? 0 ) );
		self::assertSame( array(), $GLOBALS['lel_test_posts'] );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' ) );
	}

	public function test_readiness_reports_contact_idempotency(): void {
		$report = System_Readiness::report();
		self::assertArrayHasKey( 'contact_idempotency', $report['checks'] );
		self::assertSame( 'ok', $report['checks']['contact_idempotency']['status'] );

		$key = Contact_Idempotency::key_hash( self::UUID );
		Contact_Idempotency::reserve( $key );
		$this->expire_lease( $key, -4000 );
		$report = System_Readiness::report();
		self::assertSame( 'degraded', $report['checks']['contact_idempotency']['status'] );

		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_contact_idempotency' );
		$report = System_Readiness::report();
		self::assertSame( 'blocked', $report['checks']['contact_idempotency']['status'] );
	}

	public function test_retention_purges_terminal_reservations(): void {
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_contact_idempotency',
			array(
				array( 'id' => 1, 'request_key_hash' => str_repeat( 'a', 64 ), 'state' => 'completed', 'message_post_id' => 5, 'lease_expires_at' => null, 'created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-01 00:00:00', 'completed_at' => '2000-01-01 00:00:00', 'schema_version' => 1 ),
				array( 'id' => 2, 'request_key_hash' => str_repeat( 'b', 64 ), 'state' => 'processing', 'message_post_id' => null, 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 120 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'completed_at' => null, 'schema_version' => 1 ),
			)
		);
		$purged = Contact_Idempotency::purge_terminal( 7 * 86400 );
		self::assertSame( 1, $purged, 'Old terminal reservations must be purged; live ones retained.' );
		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_contact_idempotency' );
		self::assertCount( 1, $rows );
		self::assertSame( 'processing', $rows[0]['state'] );
	}

	public function test_migration_19_owns_the_idempotency_table(): void {
		self::assertGreaterThanOrEqual( 19, \Longevity\Core\Migrations::CURRENT_VERSION );
	}
}
