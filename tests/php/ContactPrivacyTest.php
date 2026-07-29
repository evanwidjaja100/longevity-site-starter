<?php

use Longevity\Core\Public_Contact;
use PHPUnit\Framework\TestCase;

final class ContactPrivacyTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_rate_limits', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_notification_outbox', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_contact_idempotency', array() );
		$GLOBALS['lel_test_posts']   = array();
		$GLOBALS['lel_test_meta']    = array();
		$GLOBALS['lel_test_mails']   = array();
		$GLOBALS['lel_test_redirects'] = array();
		unset( $GLOBALS['lel_test_wp_die'], $GLOBALS['lel_test_fail_rate_insert'], $GLOBALS['lel_test_fail_idem_insert'], $GLOBALS['lel_test_fail_idem_update'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		unset( $_SERVER['CONTENT_LENGTH'] );
		$GLOBALS['lel_test_options']['admin_email'] = 'admin@example.com';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_rate_limits', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_notification_outbox', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_contact_idempotency', array() );
		unset( $GLOBALS['lel_test_wp_die'], $GLOBALS['lel_test_fail_rate_insert'], $GLOBALS['lel_test_fail_idem_insert'], $GLOBALS['lel_test_fail_idem_update'] );
		$_POST = array();
		$_GET  = array();
		unset( $_SERVER['CONTENT_LENGTH'] );
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
			'longevity_contact_request_id' => '12345678-1234-4123-8123-123456789abc',
		);
	}

	/** Assert a rejected payload leaves neither aggregate nor notification. */
	private function assert_rejected( array $submission, string $error ): void {
		$_POST = $submission;
		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Rejected submission should redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'lel_test_redirect', $exception->getMessage() );
		}
		$redirect = end( $GLOBALS['lel_test_redirects'] );
		self::assertStringContainsString( 'contact_error=' . $error, (string) $redirect['location'] );
		self::assertSame( 303, (int) $redirect['status'] );
		self::assertSame( array(), $GLOBALS['lel_test_posts'] );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' ) );
		self::assertSame( array(), $GLOBALS['lel_test_mails'] );
	}

	public function test_rate_limit_identifier_is_deterministic_and_versioned(): void {
		$one = Public_Contact::rate_limit_identifier( '203.0.113.9', 1 );
		self::assertSame( $one, Public_Contact::rate_limit_identifier( '203.0.113.9', 1 ) );
		self::assertNotSame( $one, Public_Contact::rate_limit_identifier( '203.0.113.9', 2 ) );
		self::assertStringNotContainsString( '203.0.113.9', $one );
	}

	public function test_untrusted_forwarded_header_is_ignored(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
		self::assertSame( '198.51.100.10', Public_Contact::get_client_ip() );
	}

	public function test_contact_form_contains_sensitive_health_warning_and_inert_honeypot(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		$html = Public_Contact::render_contact_form();
		self::assertStringContainsString( 'Do not submit diagnoses', $html );
		self::assertStringContainsString( 'class="longevity-honeypot"', $html );
		self::assertStringContainsString( 'aria-hidden="true"', $html );
		self::assertStringContainsString( ' inert', $html );
		self::assertStringContainsString( 'tabindex="-1"', $html );
	}

	public function test_contact_form_declares_autocomplete_hints(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		$html = Public_Contact::render_contact_form();
		self::assertStringContainsString( 'autocomplete="name"', $html );
		self::assertStringContainsString( 'autocomplete="email"', $html );
	}

	public function test_success_feedback_uses_status_role(): void {
		$_GET = array( 'submitted' => '1' );
		$html = Public_Contact::render_contact_feedback();
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'has been received', $html );
		$_GET = array();
	}

	public function test_error_feedback_is_allowlisted_and_uses_alert_role(): void {
		$_GET = array( 'contact_error' => 'rate_limited' );
		$html = Public_Contact::render_contact_feedback();
		self::assertStringContainsString( 'role="alert"', $html );
		self::assertStringContainsString( 'Too many submissions', $html );

		$_GET = array( 'contact_error' => 'save_failed<script>' );
		self::assertSame( '', Public_Contact::render_contact_feedback() );

		$_GET = array( 'contact_error' => 'unknown_key' );
		self::assertSame( '', Public_Contact::render_contact_feedback() );
		$_GET = array();
	}

	public function test_current_rate_count_is_null_when_rate_table_missing(): void {
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_rate_limits' );
		self::assertNull( Public_Contact::current_rate_count( 'lel_contact_count_x' ) );
	}

	public function test_contact_form_is_withheld_when_limiter_unavailable(): void {
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_rate_limits' );
		$html = Public_Contact::render_contact_form();
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringContainsString( 'longevity-contact-unavailable', $html );
	}

	public function test_submission_refused_with_503_when_rate_table_missing(): void {
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_rate_limits' );
		$_POST = $this->valid_submission();

		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Submission must halt when the rate limiter is unavailable.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_wp_die', $e->getMessage(), 'Expected a wp_die() 503 halt, got: ' . $e->getMessage() );
		}
		self::assertSame( 503, (int) ( $GLOBALS['lel_test_wp_die']['args']['response'] ?? 0 ) );
		self::assertSame( array(), $GLOBALS['lel_test_posts'], 'No message may be persisted when the limiter is unavailable.' );
		self::assertSame( array(), $GLOBALS['lel_test_mails'], 'No mail may be sent when the limiter is unavailable.' );
	}

	public function test_submission_refused_with_503_when_rate_write_fails(): void {
		$GLOBALS['lel_test_fail_rate_insert'] = true;
		$_POST = $this->valid_submission();

		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Submission must halt when the rate counter cannot be written.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_wp_die', $e->getMessage(), 'Expected a wp_die() 503 halt, got: ' . $e->getMessage() );
		}
		self::assertSame( 503, (int) ( $GLOBALS['lel_test_wp_die']['args']['response'] ?? 0 ) );
		self::assertSame( array(), $GLOBALS['lel_test_posts'] );
		self::assertSame( array(), $GLOBALS['lel_test_mails'] );
	}

	public function test_raw_header_injection_is_rejected_before_sanitization(): void {
		$submission = $this->valid_submission();
		$submission['longevity_contact_name'] = "Jane\r\nBcc: victim@example.com";
		$this->assert_rejected( $submission, 'invalid_chars' );

		$submission = $this->valid_submission();
		$submission['longevity_contact_email'] = "jane@example.com\nCc: victim@example.com";
		$this->assert_rejected( $submission, 'invalid_chars' );
	}

	public function test_raw_field_and_request_body_limits_leave_no_state(): void {
		$submission = $this->valid_submission();
		$submission['longevity_contact_name'] = str_repeat( 'n', 101 );
		$this->assert_rejected( $submission, 'name_too_long' );

		$GLOBALS['lel_test_redirects'] = array();
		$submission = $this->valid_submission();
		$submission['longevity_contact_message'] = str_repeat( 'm', 5001 );
		$this->assert_rejected( $submission, 'message_too_long' );

		$GLOBALS['lel_test_redirects'] = array();
		$_SERVER['CONTENT_LENGTH'] = '16385';
		$this->assert_rejected( $this->valid_submission(), 'message_too_long' );
	}

	public function test_over_limit_submission_is_rejected_without_ddl_in_request_path(): void {
		$identifier = Public_Contact::rate_limit_identifier( Public_Contact::get_client_ip() );
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_rate_limits',
			array(
				array( 'rate_key' => 'lel_contact_count_' . $identifier, 'hit_count' => 6, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3000 ) ),
			)
		);
		$_POST     = $this->valid_submission();
		$log_start = count( $GLOBALS['wpdb']->lel_query_log );

		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Over-limit submission must be rejected.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_redirect', $e->getMessage() );
		}
		$redirect = end( $GLOBALS['lel_test_redirects'] );
		self::assertStringContainsString( 'contact_error=rate_limited', (string) $redirect['location'] );
		self::assertSame( 303, (int) $redirect['status'] );
		self::assertSame( array(), $GLOBALS['lel_test_posts'] );

		$request_queries = array_slice( $GLOBALS['wpdb']->lel_query_log, $log_start );
		foreach ( $request_queries as $sql ) {
			self::assertStringNotContainsStringIgnoringCase( 'SHOW TABLES', $sql, 'Request path must not probe for tables.' );
			self::assertStringNotContainsStringIgnoringCase( 'CREATE TABLE', $sql, 'Request path must not issue DDL.' );
		}
	}

	public function test_accepted_submission_increments_counter_and_persists(): void {
		$_POST = $this->valid_submission();

		try {
			Public_Contact::handle_contact_submission();
			self::fail( 'Accepted submission should end in a redirect.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'lel_test_redirect', $e->getMessage() );
		}
		$redirect = end( $GLOBALS['lel_test_redirects'] );
		self::assertStringContainsString( 'submitted=1', (string) $redirect['location'] );
		self::assertCount( 1, $GLOBALS['lel_test_posts'] );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_notification_outbox' ) );

		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_rate_limits' );
		self::assertCount( 1, $rows );
		self::assertSame( 1, (int) $rows[0]['hit_count'] );
	}

	public function test_readiness_reports_contact_rate_limiter(): void {
		$report = \Longevity\Core\System_Readiness::report();
		self::assertArrayHasKey( 'contact_rate_limiter', $report['checks'] );
		self::assertSame( 'ok', $report['checks']['contact_rate_limiter']['status'] );

		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_rate_limits' );
		$report = \Longevity\Core\System_Readiness::report();
		self::assertSame( 'blocked', $report['checks']['contact_rate_limiter']['status'] );
	}
}
