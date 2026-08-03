<?php
/**
 * Approval fingerprint completeness invariants (T01-04).
 *
 * Proves that dependency payloads include EVERY governed record (claim 201+
 * must influence the fingerprint) and that retrieval never silently truncates.
 *
 * @package LongevityCore
 */

declare(strict_types=1);

use Longevity\Core\Approval_Fingerprint;
use Longevity\Core\Governed_Query;
use PHPUnit\Framework\TestCase;

final class FingerprintCompletenessTest extends TestCase {

	private const PARENT_ID = 42;

	protected function setUp(): void {
		$GLOBALS['lel_test_posts'] = array();
		$GLOBALS['lel_test_meta']  = array();
		$parent                    = new WP_Post();
		$parent->ID                = self::PARENT_ID;
		$parent->post_type         = 'post';
		$parent->post_title        = 'Parent';
		$GLOBALS['lel_test_posts'][ self::PARENT_ID ] = $parent;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_posts'], $GLOBALS['lel_test_meta'], $GLOBALS['lel_test_get_posts_result'] );
	}

	private function seed_claims( int $count, int $start_id = 1000 ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$id                = $start_id + $i;
			$claim             = new WP_Post();
			$claim->ID         = $id;
			$claim->post_type  = 'lel_claim';
			$claim->post_status = 'publish';
			$GLOBALS['lel_test_posts'][ $id ] = $claim;
			$GLOBALS['lel_test_meta'][ $id ]  = array(
				'post_id'    => (string) self::PARENT_ID,
				'claim_text' => 'claim ' . $id,
			);
		}
	}

	public function test_claim_201_changes_fingerprint(): void {
		$this->seed_claims( 201 );
		$before = Approval_Fingerprint::build( self::PARENT_ID, 'fact_check' );

		// Mutate ONLY the 201st claim (highest ID).
		$GLOBALS['lel_test_meta'][ 1200 ]['claim_text'] = 'materially different claim';
		$after = Approval_Fingerprint::build( self::PARENT_ID, 'fact_check' );

		$this->assertNotSame(
			$before['combined_hash'],
			$after['combined_hash'],
			'Claim 201 was excluded from the approval fingerprint (silent truncation).'
		);
	}

	public function test_exact_boundaries_are_complete(): void {
		foreach ( array( 0, 1, 200, 201 ) as $count ) {
			$GLOBALS['lel_test_posts'] = array( self::PARENT_ID => $GLOBALS['lel_test_posts'][ self::PARENT_ID ] );
			$GLOBALS['lel_test_meta']  = array();
			$this->seed_claims( $count );
			$payload = Approval_Fingerprint::build( self::PARENT_ID, 'fact_check' )['payload'];
			$this->assertCount( $count, $payload['dependencies'], "Expected {$count} claims in dependency payload." );
		}
	}

	public function test_fingerprint_is_insertion_order_independent(): void {
		$this->seed_claims( 3 );
		$first = Approval_Fingerprint::build( self::PARENT_ID, 'fact_check' )['combined_hash'];

		// Re-seed the same data in reverse insertion order.
		$posts = $GLOBALS['lel_test_posts'];
		$GLOBALS['lel_test_posts'] = array_replace( array(), array_reverse( $posts, true ) );
		$second = Approval_Fingerprint::build( self::PARENT_ID, 'fact_check' )['combined_hash'];

		$this->assertSame( $first, $second );
	}

	public function test_hard_cap_throws_instead_of_truncating(): void {
		$this->seed_claims( 7 );
		$this->expectException( RuntimeException::class );
		Governed_Query::ids_by_meta( array( 'lel_claim' ), 'post_id', (string) self::PARENT_ID, null, 5 );
	}

	public function test_schema_version_participates_in_combined_hash(): void {
		$this->assertSame( '1.1.0', Approval_Fingerprint::SCHEMA_VERSION );
		$this->seed_claims( 1 );
		$hashes   = Approval_Fingerprint::build( self::PARENT_ID, 'fact_check' );
		$expected = Approval_Fingerprint::hash(
			array(
				'schema_version'     => Approval_Fingerprint::SCHEMA_VERSION,
				'content_hash'       => $hashes['content_hash'],
				'governed_meta_hash' => $hashes['governed_meta_hash'],
				'dependency_hash'    => $hashes['dependency_hash'],
			)
		);
		$legacy = Approval_Fingerprint::hash(
			array(
				'schema_version'     => '1.0.0',
				'content_hash'       => $hashes['content_hash'],
				'governed_meta_hash' => $hashes['governed_meta_hash'],
				'dependency_hash'    => $hashes['dependency_hash'],
			)
		);
		$this->assertSame( $expected, $hashes['combined_hash'] );
		$this->assertNotSame( $legacy, $hashes['combined_hash'], 'Snapshots hashed under an older schema version must read as stale.' );
	}
}
