<?php

use Longevity\Core\Claims;
use PHPUnit\Framework\TestCase;

final class ClaimVerificationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_meta'] = array(
			71 => array(
				'claim_id'          => 'TEST-CLAIM-71',
				'claim_text'        => 'A bounded synthetic claim.',
				'source_url'        => 'https://example.invalid/source',
				'last_edited_by'    => 12,
				'verification_status' => 'not_verified',
			),
		);
		$post = new WP_Post();
		$post->ID = 71;
		$post->post_type = 'lel_claim';
		$GLOBALS['lel_test_posts'][71] = $post;
		$GLOBALS['lel_test_user_caps'][12] = array( 'verify_claims' );
		$GLOBALS['lel_test_user_caps'][13] = array( 'verify_claims' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_meta'], $GLOBALS['lel_test_posts'], $GLOBALS['lel_test_user_caps'] );
	}

	public function test_last_editor_cannot_verify_their_own_claim_snapshot(): void {
		self::assertFalse( Claims::verify( 71, 12 ) );
		self::assertSame( 'not_verified', get_post_meta( 71, 'verification_status', true ) );
	}

	public function test_independent_verifier_creates_hash_bound_provenance(): void {
		self::assertTrue( Claims::verify( 71, 13 ) );
		self::assertSame( 'verified', get_post_meta( 71, 'verification_status', true ) );
		self::assertSame( 13, get_post_meta( 71, 'verified_by', true ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) get_post_meta( 71, 'verification_snapshot_hash', true ) );
	}

	public function test_missing_source_rejects_verification(): void {
		$GLOBALS['lel_test_meta'][71]['source_url'] = '';
		self::assertFalse( Claims::verify( 71, 13 ) );
	}
}
