<?php

use Longevity\Core\Affiliate_Registry;
use PHPUnit\Framework\TestCase;

final class AffiliateRegistryTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_pages_by_slug'] );
		unset( $GLOBALS['lel_test_meta'] );
	}

	public function test_content_has_affiliate_link_detects_shortcode(): void {
		self::assertTrue( Affiliate_Registry::content_has_affiliate_link( 'Buy now [affiliate_link url="https://example.com"]here[/affiliate_link]' ) );
	}

	public function test_content_has_affiliate_link_detects_sponsored_rel(): void {
		self::assertTrue( Affiliate_Registry::content_has_affiliate_link( '<a href="https://example.com" rel="sponsored">buy</a>' ) );
		self::assertTrue( Affiliate_Registry::content_has_affiliate_link( "<a href='https://example.com' rel='sponsored'>buy</a>" ) );
	}

	public function test_content_has_affiliate_link_returns_false_for_clean_content(): void {
		self::assertFalse( Affiliate_Registry::content_has_affiliate_link( 'Just plain text with no links.' ) );
	}

	public function test_all_destinations_registered_empty_content_passes(): void {
		self::assertTrue( Affiliate_Registry::all_destinations_registered( '' ) );
		self::assertTrue( Affiliate_Registry::all_destinations_registered( '<p>No links here.</p>' ) );
	}

	public function test_all_destinations_registered_returns_false_for_unregistered_url(): void {
		$GLOBALS['lel_test_meta'] = array();
		$content = '[affiliate_link url="https://unregistered.example.com"]Shop[/affiliate_link]';
		self::assertFalse( Affiliate_Registry::all_destinations_registered( $content ) );
	}
}
