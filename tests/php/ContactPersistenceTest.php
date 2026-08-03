<?php

use Longevity\Core\Notification_Outbox;
use Longevity\Core\Public_Contact;
use PHPUnit\Framework\TestCase;

final class ContactPersistenceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_rate_limits', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_notification_outbox', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_contact_idempotency', array() );
		$GLOBALS['lel_test_posts']         = array();
		$GLOBALS['lel_test_meta']          = array();
		$GLOBALS['lel_test_mails']         = array();
		$GLOBALS['lel_test_redirects']     = array();
		$GLOBALS['lel_test_deleted_posts'] = array();
		$GLOBALS['lel_test_has_action']    = array();
		$GLOBALS['lel_test_options']['admin_email'] = 'admin@example.com';
		$GLOBALS['wpdb']->last_error = '';
		unset( $GLOBALS['lel_test_options']['lel_contact_reconciliation_1'], $GLOBALS['lel_test_options']['lel_contact_reconciliation_999'] );
		unset( $GLOBALS['lel_test_wp_die'], $GLOBALS['lel_test_drop_meta_keys'], $GLOBALS['lel_test_fail_wp_mail'], $GLOBALS['lel_test_fail_wp_insert_post'], $GLOBALS['lel_test_fail_insert'], $GLOBALS['lel_test_fail_idem_insert'], $GLOBALS['lel_test_fail_idem_update'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_rate_limits', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_notification_outbox', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_contact_idempotency', array() );
		unset( $GLOBALS['lel_test_wp_die'], $GLOBALS['lel_test_drop_meta_keys'], $GLOBALS['lel_test_fail_wp_mail'], $GLOBALS['lel_test_fail_wp_insert_post'], $GLOBALS['lel_test_has_action'], $GLOBALS['lel_test_fail_insert'], $GLOBALS['lel_test_fail_idem_insert'], $GLOBALS['lel_test_fail_idem_update'] );
		$_POST = array();
	}

	/** @return array<string, string> */
	private function valid_submission(): array {
		return array(
			'_wpnonce'                  => 'test_nonce_longevity_contact',
			'longevity_website'         => '',
			'longevity_contact_name'    => 'Jane Doe',
			'longevity_contact_email'   => 'jane@example.com',
			'longevity_contact_subject' => 'general',
			'longevity_contact_message' => 'Hello there.',
			'longevity_contact_request_id' => '22345678-1234-4123-8123-123456789abc',
		);
	}

	public function test_meta_readback_failure_compensates_and_refuses(): void {
		$GLOBALS['lel_test_drop_meta_keys'] = array( 'contact_email' );
		$_POST = $this->valid_submission();

		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Submission must halt when metadata cannot be verified.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_wp_die', $e->getMessage(), 'Expected a wp_die() 503 halt, got: ' . $e->getMessage() );
		}
		self::assertSame( 503, (int) ( $GLOBALS['lel_test_wp_die']['args']['response'] ?? 0 ) );
		self::assertSame( array(), $GLOBALS['lel_test_posts'], 'Partial record must be deleted (compensation).' );
		self::assertNotEmpty( $GLOBALS['lel_test_deleted_posts'], 'Compensating delete must run.' );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' ), 'No notification may be enqueued for a failed record.' );
		self::assertSame( array(), $GLOBALS['lel_test_mails'] );
	}

	public function test_insert_failure_refuses_without_partial_state(): void {
		$GLOBALS['lel_test_fail_wp_insert_post'] = true;
		$_POST = $this->valid_submission();

		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Submission must halt when the record cannot be saved.' );
		} catch ( \RuntimeException $e ) {
			self::assertContains( $e->getMessage(), array( 'lel_test_wp_die', 'lel_test_redirect' ) );
		}
		self::assertSame( array(), $GLOBALS['lel_test_posts'] );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' ) );
		self::assertSame( array(), $GLOBALS['lel_test_mails'] );
	}

	public function test_accepted_submission_enqueues_outbox_without_direct_mail(): void {
		$_POST = $this->valid_submission();

		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Accepted submission should end in a redirect.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_redirect', $e->getMessage() );
		}
		self::assertCount( 1, $GLOBALS['lel_test_posts'] );
		$post = reset( $GLOBALS['lel_test_posts'] );
		self::assertSame( 'longevity_message', $post->post_type );
		self::assertSame( 'private', $post->post_status );
		self::assertSame( array(), $GLOBALS['lel_test_mails'], 'Mail must go through the outbox, never inline.' );
		$meta = $GLOBALS['lel_test_meta'][ $post->ID ];
		foreach ( array( 'contact_subject', 'contact_email', 'contact_name', 'contact_network_id', 'contact_rate_key_version', 'contact_submitted', 'contact_retention_until', 'contact_retention_until_gmt', 'contact_privacy_version', 'contact_idempotency_key' ) as $required ) {
			self::assertArrayHasKey( $required, $meta );
			self::assertNotSame( '', (string) $meta[ $required ] );
		}

		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' );
		self::assertCount( 1, $rows );
		self::assertSame( 'pending', $rows[0]['status'] );
		self::assertSame( 'admin@example.com', $rows[0]['recipient'] );
		self::assertSame( 'contact_admin_notification', $rows[0]['notification_type'] );
		self::assertSame( (int) $post->ID, (int) $rows[0]['object_id'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $rows[0]['dedupe_key'] );
	}

	public function test_outbox_failure_compensates_and_refuses_acceptance(): void {
		$GLOBALS['lel_test_fail_insert'] = true;
		$_POST = $this->valid_submission();
		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'An accepted aggregate requires a durable outbox row.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'lel_test_wp_die', $exception->getMessage() );
		}
		self::assertSame( 503, (int) $GLOBALS['lel_test_wp_die']['args']['response'] );
		self::assertSame( array(), $GLOBALS['lel_test_posts'] );
		self::assertNotEmpty( $GLOBALS['lel_test_deleted_posts'] );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' ) );
	}

	public function test_missing_outbox_table_still_compensates_contact(): void {
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_notification_outbox' );
		$_POST = $this->valid_submission();
		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Unavailable outbox must refuse acceptance.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'lel_test_wp_die', $exception->getMessage() );
		}
		self::assertSame( array(), $GLOBALS['lel_test_posts'], 'A confirmed-absent outbox has no notification PII and must not prevent compensation.' );
		self::assertSame( array(), get_option( 'lel_contact_reconciliation_1', array() ) );
	}

	public function test_failed_compensating_post_delete_leaves_durable_reconciliation_state(): void {
		$method = new ReflectionMethod( Public_Contact::class, 'delete_contact_checked' );
		self::assertFalse( $method->invoke( null, 999, false, 'test_compensation' ) );
		$state = get_option( 'lel_contact_reconciliation_999', array() );
		self::assertSame( 999, $state['contact_id'] );
		self::assertSame( 'test_compensation:post_delete', $state['reason'] );
	}

	public function test_repeated_request_id_is_idempotent(): void {
		$_POST = $this->valid_submission();
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			try {
				Public_Contact::handle_contact_submission();
				self::fail( 'Submission should redirect.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'lel_test_redirect', $exception->getMessage() );
			}
		}
		self::assertCount( 1, $GLOBALS['lel_test_posts'] );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' ) );
	}

	public function test_outbox_rows_stay_pending_without_transport(): void {
		Notification_Outbox::enqueue( 'contact_admin_notification', 7, 'admin@example.com', array( 'subject' => 's', 'body' => 'b', 'headers' => array() ) );
		self::assertSame( 0, Notification_Outbox::process_batch() );
		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' );
		self::assertSame( 'pending', $rows[0]['status'] );
		self::assertSame( array(), $GLOBALS['lel_test_mails'] );
	}

	public function test_outbox_exposes_every_delivery_state(): void {
		self::assertSame(
			array( 'pending', 'leased', 'delivered', 'retry_wait', 'dead_letter', 'cancelled' ),
			array(
				Notification_Outbox::STATUS_PENDING,
				Notification_Outbox::STATUS_LEASED,
				Notification_Outbox::STATUS_DELIVERED,
				Notification_Outbox::STATUS_RETRY_WAIT,
				Notification_Outbox::STATUS_DEAD_LETTER,
				Notification_Outbox::STATUS_CANCELLED,
			)
		);
	}

	public function test_outbox_sends_with_transport_and_marks_sent(): void {
		$GLOBALS['lel_test_has_action']['phpmailer_init'] = true;
		Notification_Outbox::enqueue( 'contact_admin_notification', 7, 'admin@example.com', array( 'subject' => 'Contact', 'body' => 'Body text', 'headers' => array( 'Reply-To: a <a@example.com>' ) ) );

		self::assertSame( 1, Notification_Outbox::process_batch() );
		self::assertCount( 1, $GLOBALS['lel_test_mails'] );
		self::assertSame( 'admin@example.com', $GLOBALS['lel_test_mails'][0]['to'] );

		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' );
		self::assertSame( Notification_Outbox::STATUS_DELIVERED, $rows[0]['status'] );
		self::assertNotEmpty( $rows[0]['sent_at'] );
	}

	public function test_outbox_failure_retries_then_dead_letters(): void {
		$GLOBALS['lel_test_has_action']['phpmailer_init'] = true;
		$GLOBALS['lel_test_fail_wp_mail']                 = true;
		Notification_Outbox::enqueue( 'contact_admin_notification', 7, 'admin@example.com', array( 'subject' => 's', 'body' => 'b', 'headers' => array() ) );

		Notification_Outbox::process_batch();
		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' );
		self::assertStringContainsString( "status = 'retry_wait'", implode( "\n", $GLOBALS['wpdb']->lel_query_log ) );
		self::assertSame( 1, (int) $rows[0]['attempts'] );
		self::assertNotEmpty( $rows[0]['last_error'] );

		// Exhaust remaining attempts (lease expiry simulated by clearing it).
		for ( $i = 0; $i < 10; $i++ ) {
			$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' );
			if ( (int) $rows[0]['attempts'] >= Notification_Outbox::MAX_ATTEMPTS ) {
				break;
			}
			$rows[0]['status']           = Notification_Outbox::STATUS_PENDING;
			$rows[0]['lease_owner']      = null;
			$rows[0]['lease_expires_at'] = null;
			$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_notification_outbox', $rows );
			Notification_Outbox::process_batch();
		}
		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' );
		self::assertSame( Notification_Outbox::MAX_ATTEMPTS, (int) $rows[0]['attempts'] );
		self::assertStringContainsString( "status = 'dead_letter'", implode( "\n", $GLOBALS['wpdb']->lel_query_log ) );
	}

	public function test_outbox_lease_transitions_require_exactly_one_owned_row(): void {
		$success = new ReflectionMethod( Notification_Outbox::class, 'finish_success' );
		$failure = new ReflectionMethod( Notification_Outbox::class, 'finish_failure' );

		self::assertFalse( $success->invoke( null, 999, 'other-worker' ) );
		self::assertFalse( $failure->invoke( null, array( 'id' => 999, 'attempts' => 0 ), 'other-worker', 'transport_rejected' ) );
		self::assertFalse( $failure->invoke( null, array( 'id' => 999, 'attempts' => Notification_Outbox::MAX_ATTEMPTS - 1 ), 'other-worker', 'transport_rejected' ) );

		$queries = implode( "\n", $GLOBALS['wpdb']->lel_query_log );
		self::assertStringContainsString( "status = 'delivered'", $queries );
		self::assertStringContainsString( "status = 'retry_wait'", $queries );
		self::assertStringContainsString( "status = 'dead_letter'", $queries );
		self::assertStringContainsString( "status = 'leased' AND lease_owner = 'other-worker'", $queries );
	}

	public function test_outbox_stats_report_missing_or_failed_queries_as_unavailable(): void {
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_notification_outbox' );
		$stats = Notification_Outbox::stats();
		self::assertFalse( $stats['available'] );
		self::assertSame( 'table_missing', $stats['error'] );
		self::assertNull( $stats['pending'] );

		$original = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public string $last_error = '';

			public function prepare( string $query, ...$args ): string {
				return str_replace( '%s', "'" . (string) ( $args[0] ?? '' ) . "'", $query );
			}

			public function get_var( string $query ) {
				if ( str_contains( $query, 'SHOW TABLES' ) ) {
					return 'wp_lel_notification_outbox';
				}
				$this->last_error = 'simulated count failure';
				return false;
			}
		};
		try {
			$stats = Notification_Outbox::stats();
			self::assertFalse( $stats['available'] );
			self::assertSame( 'query_failed', $stats['error'] );
			self::assertNull( $stats['failed'] );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	public function test_readiness_reports_notification_outbox(): void {
		$report = \Longevity\Core\System_Readiness::report();
		self::assertArrayHasKey( 'notification_outbox', $report['checks'] );
		self::assertSame( 'ok', $report['checks']['notification_outbox']['status'] );

		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_notification_outbox',
			array( array( 'id' => 1, 'notification_type' => 't', 'object_id' => 1, 'recipient' => 'a@example.com', 'status' => 'failed', 'attempts' => 5, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) )
		);
		$report = \Longevity\Core\System_Readiness::report();
		self::assertSame( 'degraded', $report['checks']['notification_outbox']['status'] );

		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_notification_outbox' );
		$report = \Longevity\Core\System_Readiness::report();
		self::assertSame( 'blocked', $report['checks']['notification_outbox']['status'] );
	}

	public function test_migration_13_owns_the_outbox_table(): void {
		self::assertGreaterThanOrEqual( 13, \Longevity\Core\Migrations::CURRENT_VERSION );
	}
}
