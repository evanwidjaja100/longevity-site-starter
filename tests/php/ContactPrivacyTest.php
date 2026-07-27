<?php

use Longevity\Core\Public_Contact;
use PHPUnit\Framework\TestCase;

final class ContactPrivacyTest extends TestCase {
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
		$_GET = array( 'error' => 'rate_limited' );
		$html = Public_Contact::render_contact_feedback();
		self::assertStringContainsString( 'role="alert"', $html );
		self::assertStringContainsString( 'Too many submissions', $html );

		$_GET = array( 'error' => 'save_failed<script>' );
		self::assertSame( '', Public_Contact::render_contact_feedback() );

		$_GET = array( 'error' => 'unknown_key' );
		self::assertSame( '', Public_Contact::render_contact_feedback() );
		$_GET = array();
	}
}
