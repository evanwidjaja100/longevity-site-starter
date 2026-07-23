<?php

use Longevity\Core\Public_Trust;
use Longevity\Core\Public_Nav;
use PHPUnit\Framework\TestCase;

final class PublicComponentsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_pages_by_slug'] );
		unset( $GLOBALS['lel_test_permalinks'] );
	}

	public function test_render_medical_disclaimer_contains_class_and_text(): void {
		$html = Public_Trust::render_medical_disclaimer();
		self::assertStringContainsString( 'longevity-medical-disclaimer', $html );
		self::assertStringContainsString( 'Medical disclaimer:', $html );
		self::assertStringContainsString( 'educational', $html );
	}

	public function test_render_medical_disclaimer_uses_note_role(): void {
		$html = Public_Trust::render_medical_disclaimer();
		self::assertStringContainsString( 'role="note"', $html );
	}

	public function test_render_policy_links_contains_all_policy_slugs(): void {
		$GLOBALS['lel_test_pages_by_slug'] = array(
			'about' => (object) array( 'ID' => 10 ),
			'privacy' => (object) array( 'ID' => 20 ),
		);
		$GLOBALS['lel_test_permalinks'] = array(
			10 => 'http://example.com/about/',
			20 => 'http://example.com/privacy/',
		);
		$html = Public_Nav::render_policy_links();
		self::assertStringContainsString( 'longevity-policy-nav', $html );
		self::assertStringContainsString( 'About', $html );
		self::assertStringContainsString( 'Editorial Policy', $html );
		self::assertStringContainsString( 'Privacy', $html );
		self::assertStringContainsString( 'http://example.com/about/', $html );
	}

	public function test_render_policy_links_falls_back_to_home_url_when_page_missing(): void {
		$GLOBALS['lel_test_pages_by_slug'] = array();
		$GLOBALS['lel_test_permalinks'] = array();
		$html = Public_Nav::render_policy_links();
		self::assertStringContainsString( '/about/', $html );
		self::assertStringContainsString( '/privacy/', $html );
	}
}
