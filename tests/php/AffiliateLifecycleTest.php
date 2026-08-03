<?php

use Longevity\Core\Affiliate_Registry;
use PHPUnit\Framework\TestCase;

final class AffiliateLifecycleTest extends TestCase {
	private function record(): array {
		return array(
			'merchant_domain' => 'example.com',
			'relationship_status' => 'active',
			'effective_date' => '2026-01-01',
			'expiration_date' => '2026-12-31',
			'last_verified_date' => '2026-07-01',
			'allow_subdomains' => false,
		);
	}

	public function test_normalization_rejects_credentials_ports_and_deceptive_domains(): void {
		self::assertSame( 'example.com', Affiliate_Registry::normalize_destination( 'HTTPS://WWW.Example.COM./path' )['host'] );
		self::assertNull( Affiliate_Registry::normalize_destination( 'https://user:pass@example.com/' ) );
		self::assertNull( Affiliate_Registry::normalize_destination( 'https://example.com:8443/' ) );
		self::assertFalse( Affiliate_Registry::relationship_is_eligible( $this->record(), 'https://example.com.evil.test/', '2026-07-21' ) );
	}

	public function test_lifecycle_and_explicit_subdomain_policy(): void {
		self::assertTrue( Affiliate_Registry::relationship_is_eligible( $this->record(), 'https://www.example.com/item', '2026-07-21' ) );
		self::assertFalse( Affiliate_Registry::relationship_is_eligible( $this->record(), 'https://shop.example.com/item', '2026-07-21' ) );
		$record = $this->record();
		$record['allow_subdomains'] = true;
		self::assertTrue( Affiliate_Registry::relationship_is_eligible( $record, 'https://shop.example.com/item', '2026-07-21' ) );
		self::assertFalse( Affiliate_Registry::relationship_is_eligible( $record, 'https://example.com/item', '2027-01-01' ) );
	}
}
