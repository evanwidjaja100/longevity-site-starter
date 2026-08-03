<?php

use Longevity\Core\Affiliate_Registry;
use PHPUnit\Framework\TestCase;

/**
 * PR-09 — complete affiliate destination validation.
 *
 * Proves that validation covers every extracted destination and every
 * registry record, that ambiguity and parser failures fail closed, and
 * that malformed destinations never pass silently.
 */
final class AffiliateValidationCompletenessTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_posts'] = array();
		$GLOBALS['lel_test_meta']  = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['lel_test_posts'],
			$GLOBALS['lel_test_meta'],
			$GLOBALS['lel_test_get_posts_result'],
			$GLOBALS['lel_test_html_processor_throws']
		);
	}

	private function add_merchant( int $id, string $domain, string $status = 'active', bool $allow_subdomains = false ): void {
		$post            = new WP_Post();
		$post->ID        = $id;
		$post->post_type = 'lel_affiliate';
		$post->post_status = 'private';

		$GLOBALS['lel_test_posts'][ $id ] = $post;
		$GLOBALS['lel_test_meta'][ $id ]  = array(
			'merchant_id'         => 'm' . $id,
			'merchant_domain'     => $domain,
			'relationship_status' => $status,
			'effective_date'      => '2020-01-01',
			'expiration_date'     => '',
			'last_verified_date'  => gmdate( 'Y-m-d' ),
			'allow_subdomains'    => $allow_subdomains ? '1' : '',
		);
	}

	public function test_unregistered_link_at_position_101_blocks_validation(): void {
		$this->add_merchant( 10, 'registered.test' );
		$content = '';
		for ( $i = 1; $i <= 100; $i++ ) {
			$content .= sprintf( '[affiliate_link url="https://registered.test/product-%d"]Buy[/affiliate_link] ', $i );
		}
		$content .= '[affiliate_link url="https://unregistered.test/rogue"]Buy[/affiliate_link]';
		self::assertFalse( Affiliate_Registry::all_destinations_registered( $content ) );
	}

	public function test_valid_merchant_after_registry_record_100_is_recognized(): void {
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->add_merchant( $i, sprintf( 'merchant-%d.test', $i ) );
		}
		$this->add_merchant( 101, 'late-merchant.test' );
		self::assertTrue(
			Affiliate_Registry::all_destinations_registered( '[affiliate_link url="https://late-merchant.test/item"]Buy[/affiliate_link]' )
		);
		self::assertNotNull( Affiliate_Registry::find_by_url( 'https://late-merchant.test/item' ) );
	}

	public function test_duplicate_registry_rows_for_same_destination_fail_closed(): void {
		$this->add_merchant( 21, 'dup.test' );
		$this->add_merchant( 22, 'dup.test' );
		self::assertNull( Affiliate_Registry::find_by_url( 'https://dup.test/item' ) );
		self::assertFalse(
			Affiliate_Registry::all_destinations_registered( '[affiliate_link url="https://dup.test/item"]Buy[/affiliate_link]' )
		);
	}

	public function test_inactive_merchant_is_not_a_match(): void {
		$this->add_merchant( 31, 'inactive.test', 'inactive' );
		self::assertFalse(
			Affiliate_Registry::all_destinations_registered( '[affiliate_link url="https://inactive.test/item"]Buy[/affiliate_link]' )
		);
	}

	public function test_malicious_suffix_host_is_rejected(): void {
		$this->add_merchant( 41, 'example.test', 'active', true );
		self::assertFalse(
			Affiliate_Registry::all_destinations_registered( '[affiliate_link url="https://approved.example.attacker.test/x"]Buy[/affiliate_link]' )
		);
		self::assertFalse(
			Affiliate_Registry::all_destinations_registered( '[affiliate_link url="https://example.test.attacker.test/x"]Buy[/affiliate_link]' )
		);
	}

	public function test_mixed_case_and_trailing_dot_host_matches(): void {
		$this->add_merchant( 51, 'brand.test' );
		self::assertTrue(
			Affiliate_Registry::all_destinations_registered( '[affiliate_link url="HTTPS://WWW.Brand.TEST./deal"]Buy[/affiliate_link]' )
		);
	}

	public function test_sponsored_anchor_destinations_are_extracted_and_validated(): void {
		$this->add_merchant( 61, 'anchor.test' );
		self::assertTrue(
			Affiliate_Registry::all_destinations_registered( '<a href="https://anchor.test/p?a=1&amp;b=2" rel="nofollow sponsored noopener">Buy</a>' )
		);
		self::assertFalse(
			Affiliate_Registry::all_destinations_registered( "<a href='https://rogue.test/p' rel='sponsored'>Buy</a>" )
		);
	}

	public function test_malformed_sponsored_destinations_fail_closed(): void {
		$this->add_merchant( 71, 'anchor.test' );
		$cases = array(
			'<a href="/relative/path" rel="sponsored">Buy</a>',
			'<a href="//anchor.test/protocol-relative" rel="sponsored">Buy</a>',
			'<a href="#fragment-only" rel="sponsored">Buy</a>',
			'<a href="ftp://anchor.test/file" rel="sponsored">Buy</a>',
			'<a href="javascript:alert(1)" rel="sponsored">Buy</a>',
			'<a rel="sponsored">Buy</a>',
		);
		foreach ( $cases as $content ) {
			self::assertFalse( Affiliate_Registry::all_destinations_registered( $content ), 'Expected fail-closed for: ' . $content );
		}
	}

	public function test_parser_failure_blocks_affiliate_validation(): void {
		$this->add_merchant( 81, 'anchor.test' );
		$GLOBALS['lel_test_html_processor_throws'] = true;
		self::assertFalse(
			Affiliate_Registry::all_destinations_registered( '<a href="https://anchor.test/p" rel="sponsored">Buy</a>' )
		);
		self::assertTrue(
			Affiliate_Registry::content_has_affiliate_link( '<a href="https://anchor.test/p" rel="sponsored">Buy</a>' )
		);
	}

	public function test_content_without_anchors_or_shortcodes_passes_without_parser(): void {
		self::assertTrue( Affiliate_Registry::all_destinations_registered( '<p>Plain content.</p>' ) );
		self::assertFalse( Affiliate_Registry::content_has_affiliate_link( '<p>Plain content.</p>' ) );
	}

	public function test_non_sponsored_anchors_are_ignored(): void {
		self::assertTrue(
			Affiliate_Registry::all_destinations_registered( '<a href="https://citation.test/study" rel="noopener">Study</a>' )
		);
	}

	public function test_public_output_retains_required_rel_attributes(): void {
		$this->add_merchant( 91, 'shop.test' );
		$html = Affiliate_Registry::render_link( 'https://shop.test/item', 'Buy now' );
		self::assertStringContainsString( 'rel="sponsored nofollow noopener"', $html );
	}
}
