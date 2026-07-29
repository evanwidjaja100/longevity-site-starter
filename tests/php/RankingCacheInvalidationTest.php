<?php

use Longevity\Core\Metrics;
use Longevity\Core\Rankings;
use Longevity\Core\Runtime_Config;
use PHPUnit\Framework\TestCase;

/**
 * PR-04 fail-closed ranking cache invalidation.
 *
 * Invariant: a cached ranking entry may accelerate a decision but may never
 * make an ineligible review public. Every eligible id returned for public
 * rendering must be re-validated live; stale entries are dropped, counted,
 * and the cache generation is bumped.
 */
final class RankingCacheInvalidationTest extends TestCase {
	private const TRANSIENT = 'lel_rankings_eligible';

	protected function setUp(): void {
		$GLOBALS['lel_test_options']    = array();
		$GLOBALS['lel_test_transients'] = array();
		$GLOBALS['lel_test_posts']      = array();
		$GLOBALS['lel_test_meta']       = array();
		$GLOBALS['lel_test_titles']     = array();
		$GLOBALS['lel_test_get_posts_result'] = array();
		Runtime_Config::reset();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['lel_test_options'],
			$GLOBALS['lel_test_transients'],
			$GLOBALS['lel_test_posts'],
			$GLOBALS['lel_test_meta'],
			$GLOBALS['lel_test_titles'],
			$GLOBALS['lel_test_get_posts_result']
		);
		Runtime_Config::reset();
	}

	/**
	 * Reproduces the vulnerability: a legacy versioned transient produced by the
	 * previous implementation held an id that was eligible when cached but is no
	 * longer a valid review. The fixed implementation must never serve it.
	 */
	public function test_previously_cached_eligible_id_is_not_served_when_now_ineligible(): void {
		$version    = (string) get_option( 'lel_rankings_cache_version', '1' );
		$legacy_key = 'lel_rankings_eligible_ids_' . md5( $version );
		$GLOBALS['lel_test_transients'][ $legacy_key ] = array( 555 );

		$result = Rankings::eligible_ids();

		self::assertNotContains( 555, $result, 'A cached but currently-ineligible review id must never be served publicly.' );
	}

	/**
	 * A cached id that is no longer eligible must be dropped on read, recorded in
	 * the privacy-safe rejection counter, and trigger a generation bump.
	 */
	public function test_stale_cache_entry_is_dropped_and_recorded_by_live_revalidation(): void {
		// Prime the current cache signature with an empty eligible set.
		$GLOBALS['lel_test_get_posts_result'] = array();
		Rankings::eligible_ids();

		$entry = $GLOBALS['lel_test_transients'][ self::TRANSIENT ] ?? null;
		self::assertIsArray( $entry, 'eligible_ids() must persist a signature-scoped cache entry.' );
		self::assertArrayHasKey( 'signature', $entry );

		// Inject a now-ineligible id under the still-valid signature.
		$GLOBALS['lel_test_transients'][ self::TRANSIENT ]['ids'] = array( 555 );
		$generation_before = (string) get_option( 'lel_rankings_cache_version', '1' );

		$result = Rankings::eligible_ids();

		self::assertNotContains( 555, $result, 'Live revalidation must drop the stale cached id.' );
		self::assertGreaterThanOrEqual( 1, (int) get_option( 'lel_rankings_cache_rejections', 0 ), 'Rejected cached ids must be counted.' );
		self::assertNotSame( $generation_before, (string) get_option( 'lel_rankings_cache_version', '1' ), 'A live rejection must bump the cache generation.' );
	}

	public function test_invalidate_all_bumps_generation_and_forces_recompute(): void {
		$before = (string) get_option( 'lel_rankings_cache_version', '1' );
		Rankings::invalidate_all( 'unit_test' );
		$after = (string) get_option( 'lel_rankings_cache_version', '1' );
		self::assertNotSame( $before, $after, 'invalidate_all must change the cache generation.' );
	}

	public function test_invalidate_review_bumps_generation(): void {
		$before = (string) get_option( 'lel_rankings_cache_version', '1' );
		Rankings::invalidate_review( 555, 'unit_test' );
		$after = (string) get_option( 'lel_rankings_cache_version', '1' );
		self::assertNotSame( $before, $after, 'invalidate_review must change the cache generation.' );
	}

	public function test_invalidate_all_changes_signature_so_stale_cache_is_ignored(): void {
		$GLOBALS['lel_test_get_posts_result'] = array();
		Rankings::eligible_ids();
		$GLOBALS['lel_test_transients'][ self::TRANSIENT ]['ids'] = array( 555 );

		Rankings::invalidate_all( 'unit_test' );
		$result = Rankings::eligible_ids();

		self::assertNotContains( 555, $result, 'After invalidation the stale signature must be ignored and the set recomputed.' );
	}

	public function test_metrics_exposes_cache_rejection_counter(): void {
		$GLOBALS['lel_test_options']['lel_rankings_cache_rejections'] = 3;
		$payload = Metrics::render();
		self::assertStringContainsString( '# TYPE lel_rankings_cache_rejected_total counter', $payload );
		self::assertStringContainsString( 'lel_rankings_cache_rejected_total 3', $payload );
	}
}
