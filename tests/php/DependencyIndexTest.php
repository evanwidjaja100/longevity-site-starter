<?php
/**
 * Dependency index reconciliation tests.
 *
 * @package LongevityCore
 */

use Longevity\Core\Dependency_Index;
use PHPUnit\Framework\TestCase;

final class DependencyIndexTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_dependencies', array() );
		$GLOBALS['wpdb']->lel_query_log = array();
		$GLOBALS['wpdb']->last_error    = '';
		$GLOBALS['lel_test_options']    = array();
		$GLOBALS['lel_test_posts']      = array();
		$GLOBALS['lel_test_meta']       = array();
		unset( $GLOBALS['lel_test_fail_dependency_insert'], $GLOBALS['lel_test_html_processor_throws'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_fail_dependency_insert'], $GLOBALS['lel_test_html_processor_throws'] );
	}

	private static function log(): array {
		return $GLOBALS['wpdb']->lel_query_log;
	}

	private static function make_post( int $id, string $type, string $content = '' ): void {
		$post               = new stdClass();
		$post->ID           = $id;
		$post->post_type    = $type;
		$post->post_status  = 'publish';
		$post->post_content = $content;
		$GLOBALS['lel_test_posts'][ $id ] = $post;
	}

	private static function make_merchant( int $id, string $domain, string $status = 'active', bool $allow_subdomains = false ): void {
		self::make_post( $id, 'lel_affiliate' );
		$GLOBALS['lel_test_meta'][ $id ] = array(
			'merchant_domain'     => $domain,
			'relationship_status' => $status,
		);
		if ( $allow_subdomains ) {
			$GLOBALS['lel_test_meta'][ $id ]['allow_subdomains'] = '1';
		}
	}

	private static function make_affiliate_parent( int $id, string $content ): void {
		self::make_post( $id, 'post', $content );
		$GLOBALS['lel_test_meta'][ $id ] = array( '_lel_has_affiliate_links' => '1' );
	}

	public function test_replace_dependencies_is_transactional_delete_then_insert(): void {
		Dependency_Index::replace_dependencies( 'lel_claim', 10, array( 1, 2 ) );

		$log   = self::log();
		$sql   = implode( "\n", $log );
		$begin = array_search( true, array_map( static fn( string $q ): bool => (bool) preg_match( '/START TRANSACTION|BEGIN/i', $q ), $log ), true );
		self::assertNotFalse( $begin, 'replace_dependencies must run inside a transaction. Log: ' . $sql );
		self::assertMatchesRegularExpression( '/DELETE FROM wp_lel_dependencies/i', $sql );
		self::assertMatchesRegularExpression( '/INSERT IGNORE INTO wp_lel_dependencies/i', $sql );
		self::assertMatchesRegularExpression( '/COMMIT/i', $sql );
	}

	public function test_replace_dependencies_replaces_old_set(): void {
		Dependency_Index::register( 'lel_claim', 1, 10 );
		Dependency_Index::register( 'lel_claim', 2, 10 );
		Dependency_Index::register( 'lel_source', 99, 10 );

		Dependency_Index::replace_dependencies( 'lel_claim', 10, array( 2, 3 ) );

		self::assertSame( array(), Dependency_Index::find_parents( 'lel_claim', 1 ), 'Removed dependency must no longer resolve.' );
		self::assertSame( array( 10 ), Dependency_Index::find_parents( 'lel_claim', 2 ) );
		self::assertSame( array( 10 ), Dependency_Index::find_parents( 'lel_claim', 3 ) );
		self::assertSame( array( 10 ), Dependency_Index::find_parents( 'lel_source', 99 ), 'Other dependency types must be untouched.' );
	}

	public function test_partial_index_is_unioned_with_authoritative_relationships(): void {
		self::make_post( 30, 'post' );
		self::make_post( 40, 'post' );
		self::make_post( 100, 'lel_claim' );
		self::make_post( 101, 'lel_claim' );
		$GLOBALS['lel_test_meta'][100] = array( 'post_id' => '30', 'source_id' => '200' );
		$GLOBALS['lel_test_meta'][101] = array( 'post_id' => '40', 'source_id' => '200' );
		Dependency_Index::register( 'lel_source', 200, 30 );

		self::assertSame( array( 30, 40 ), Dependency_Index::find_parents( 'lel_source', 200 ) );
	}

	public function test_missing_index_still_uses_authoritative_relationships(): void {
		self::make_post( 30, 'post' );
		self::make_post( 100, 'lel_claim' );
		$GLOBALS['lel_test_meta'][100] = array( 'post_id' => '30', 'source_id' => '200' );
		$GLOBALS['wpdb']->lel_test_drop_table( 'wp_lel_dependencies' );

		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_source', 200 ) );
	}

	public function test_replace_dependencies_is_idempotent(): void {
		Dependency_Index::replace_dependencies( 'lel_claim', 10, array( 1, 2 ) );
		Dependency_Index::replace_dependencies( 'lel_claim', 10, array( 1, 2 ) );

		$rows = $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_dependencies' );
		self::assertCount( 2, $rows );
	}

	public function test_replace_dependencies_rolls_back_and_throws_on_insert_failure(): void {
		$GLOBALS['lel_test_fail_dependency_insert'] = true;

		try {
			Dependency_Index::replace_dependencies( 'lel_claim', 10, array( 1 ) );
			self::fail( 'A failed dependency insert must not be reported as success.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Could not index dependency', $error->getMessage() );
		}

		self::assertContains( 'ROLLBACK', array_map( 'trim', self::log() ) );
	}

	public function test_reindex_parent_replaces_every_dependency_type(): void {
		self::make_post( 30, 'post' );
		self::make_post( 100, 'lel_claim' );
		$GLOBALS['lel_test_meta'][100] = array( 'post_id' => '30', 'source_id' => '200' );
		$GLOBALS['lel_test_meta'][30]  = array( 'medical_reviewer_user_id' => '7' );
		foreach ( array( 'lel_claim' => 999, 'lel_source' => 888, 'lel_test_record' => 777, 'lel_protocol' => 666, 'credential' => 5, 'lel_affiliate' => 9 ) as $type => $id ) {
			Dependency_Index::register( $type, $id, 30 );
		}

		Dependency_Index::reindex_parent( 30 );

		foreach ( array( 'lel_claim' => 999, 'lel_source' => 888, 'lel_test_record' => 777, 'lel_protocol' => 666, 'credential' => 5, 'lel_affiliate' => 9 ) as $type => $id ) {
			self::assertSame( array(), Dependency_Index::find_parents( $type, $id ), $type . ' stale edge must be removed.' );
		}
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_claim', 100 ) );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_source', 200 ) );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'credential', 7 ) );
	}

	public function test_replace_dependencies_rolls_back_on_write_failure(): void {
		$GLOBALS['lel_test_fail_dependency_write'] = true;
		try {
			$this->expectException( RuntimeException::class );
			Dependency_Index::replace_dependencies( 'lel_claim', 10, array( 1 ) );
		} finally {
			unset( $GLOBALS['lel_test_fail_dependency_write'] );
		}
	}

	public function test_backfill_indexes_all_dependency_types_and_records_marker(): void {
		self::make_post( 30, 'post' );
		self::make_post( 100, 'lel_claim' );
		self::make_post( 300, 'lel_test_record' );
		$GLOBALS['lel_test_meta'][100] = array(
			'post_id'   => '30',
			'source_id' => '200',
		);
		$GLOBALS['lel_test_meta'][30]  = array(
			'test_record_id'           => '300',
			'medical_reviewer_user_id' => '7',
		);
		$GLOBALS['lel_test_meta'][300] = array( 'protocol_id' => '400' );

		$result = Dependency_Index::run_backfill( false, 200 );

		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_claim', 100 ) );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_source', 200 ) );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_test_record', 300 ) );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_protocol', 400 ) );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'credential', 7 ) );
		self::assertNotEmpty( get_option( 'lel_dependency_index_backfilled_at', '' ), 'Backfill completion marker must be recorded.' );
		self::assertSame( Dependency_Index::DATA_GENERATION, get_option( 'lel_dependency_index_generation', '' ) );
		self::assertSame( Dependency_Index::SCHEMA_VERSION, get_option( 'lel_dependency_index_schema_version', '' ) );
		self::assertTrue( $result['drift']['valid'] );
		self::assertSame( 1, $result['parents_scanned'] );
	}

	public function test_drift_check_detects_stale_and_missing_edges(): void {
		self::make_post( 30, 'post' );
		self::make_post( 100, 'lel_claim' );
		$GLOBALS['lel_test_meta'][100] = array( 'post_id' => '30' );
		Dependency_Index::register( 'lel_claim', 999, 30 );

		$drift = Dependency_Index::verify_drift();

		self::assertFalse( $drift['valid'] );
		self::assertSame( 1, $drift['missing'] );
		self::assertSame( 1, $drift['stale'] );
	}

	public function test_backfill_dry_run_writes_nothing(): void {
		self::make_post( 30, 'post' );
		self::make_post( 100, 'lel_claim' );
		$GLOBALS['lel_test_meta'][100] = array( 'post_id' => '30' );

		$result = Dependency_Index::run_backfill( true, 200 );

		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_dependencies' ) );
		self::assertSame( '', (string) get_option( 'lel_dependency_index_backfilled_at', '' ) );
		self::assertSame( 1, $result['parents_scanned'] );
	}

	public function test_backfill_resumes_same_generation_cursor(): void {
		self::make_post( 30, 'post' );
		self::make_post( 40, 'post' );
		$GLOBALS['lel_test_options']['lel_dependency_backfill_generation'] = Dependency_Index::DATA_GENERATION;
		$GLOBALS['lel_test_options']['lel_dependency_backfill_cursor']     = 30;

		$result = Dependency_Index::run_backfill( false, 200 );

		self::assertSame( 1, $result['parents_scanned'], 'A resumed generation must continue after its checkpoint instead of restarting.' );
		self::assertTrue( $result['complete'] );
	}

	public function test_affiliate_edges_bind_only_referenced_merchants(): void {
		self::make_merchant( 500, 'merchant-a.example' );
		self::make_merchant( 501, 'merchant-b.example' );
		self::make_affiliate_parent( 30, '<a href="https://merchant-a.example/product" rel="sponsored">A</a>' );

		Dependency_Index::reindex_parent( 30 );

		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 500 ) );
		self::assertSame( array(), Dependency_Index::find_parents( 'lel_affiliate', 501 ), 'A parent must not be bound to merchants it never links to.' );
	}

	public function test_merchant_change_invalidates_only_linked_parents(): void {
		self::make_merchant( 500, 'merchant-a.example' );
		self::make_merchant( 501, 'merchant-b.example' );
		self::make_affiliate_parent( 30, '<a href="https://merchant-a.example/x" rel="sponsored">A</a>' );
		self::make_affiliate_parent( 40, '<a href="https://merchant-b.example/y" rel="sponsored">B</a>' );

		Dependency_Index::reindex_parent( 30 );
		Dependency_Index::reindex_parent( 40 );

		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 500 ) );
		self::assertSame( array( 40 ), Dependency_Index::find_parents( 'lel_affiliate', 501 ) );
	}

	public function test_removing_link_removes_obsolete_edge_after_reindex(): void {
		self::make_merchant( 500, 'merchant-a.example' );
		self::make_affiliate_parent( 30, '<a href="https://merchant-a.example/x" rel="sponsored">A</a>' );
		Dependency_Index::reindex_parent( 30 );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 500 ) );

		$GLOBALS['lel_test_posts'][30]->post_content = '<p>Link removed during revision.</p>';
		Dependency_Index::reindex_parent( 30 );

		self::assertSame( array(), Dependency_Index::find_parents( 'lel_affiliate', 500 ), 'Removing the link must remove the obsolete edge after reindexing.' );
	}

	public function test_affiliate_parse_failure_falls_back_to_broad_binding(): void {
		self::make_merchant( 500, 'merchant-a.example' );
		self::make_merchant( 501, 'merchant-b.example' );
		self::make_affiliate_parent( 30, '<a href="https://merchant-a.example/x" rel="sponsored">A</a>' );
		$GLOBALS['lel_test_html_processor_throws'] = true;

		Dependency_Index::reindex_parent( 30 );

		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 500 ) );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 501 ), 'Parse failure must over-invalidate, never under-invalidate.' );
	}

	public function test_ambiguous_destination_binds_every_matching_merchant(): void {
		self::make_merchant( 500, 'dup.example' );
		self::make_merchant( 501, 'dup.example' );
		self::make_affiliate_parent( 30, '<a href="https://dup.example/x" rel="sponsored">D</a>' );

		Dependency_Index::reindex_parent( 30 );

		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 500 ) );
		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 501 ), 'Conflicting registry rows must both be treated as dependencies.' );
	}

	public function test_subdomain_link_binds_only_subdomain_permitting_merchant(): void {
		self::make_merchant( 500, 'merchant-a.example', 'active', true );
		self::make_merchant( 501, 'merchant-a.example' );
		self::make_affiliate_parent( 30, '<a href="https://shop.merchant-a.example/x" rel="sponsored">A</a>' );

		Dependency_Index::reindex_parent( 30 );

		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 500 ) );
		self::assertSame( array(), Dependency_Index::find_parents( 'lel_affiliate', 501 ), 'Exact-only merchants must not match subdomain destinations.' );
	}

	public function test_inactive_merchant_still_receives_edge_for_referenced_domain(): void {
		self::make_merchant( 500, 'merchant-a.example', 'ended' );
		self::make_affiliate_parent( 30, '<a href="https://merchant-a.example/x" rel="sponsored">A</a>' );

		Dependency_Index::reindex_parent( 30 );

		self::assertSame( array( 30 ), Dependency_Index::find_parents( 'lel_affiliate', 500 ), 'Status changes on a referenced merchant must invalidate the parent, so the edge must exist while inactive.' );
	}

	public function test_unregistered_destination_creates_no_edges(): void {
		self::make_merchant( 500, 'merchant-a.example' );
		self::make_affiliate_parent( 30, '<a href="https://unregistered.example/x" rel="sponsored">U</a>' );

		Dependency_Index::reindex_parent( 30 );

		self::assertSame( array(), Dependency_Index::find_parents( 'lel_affiliate', 500 ) );
	}

	public function test_backfill_edge_count_is_proportional_to_actual_links(): void {
		self::make_merchant( 500, 'merchant-a.example' );
		self::make_merchant( 501, 'merchant-b.example' );
		self::make_merchant( 502, 'merchant-c.example' );
		self::make_affiliate_parent( 30, '<a href="https://merchant-a.example/x" rel="sponsored">A</a>' );
		self::make_affiliate_parent( 40, '<a href="https://merchant-b.example/y" rel="sponsored">B</a>' );
		self::make_affiliate_parent( 50, '<a href="https://merchant-c.example/z" rel="sponsored">C</a>' );

		$result = Dependency_Index::run_backfill( false, 200 );

		$affiliate_rows = array_filter(
			$GLOBALS['wpdb']->lel_test_rows( 'wp_lel_dependencies' ),
			static fn( array $row ): bool => 'lel_affiliate' === $row['dependency_type']
		);
		self::assertCount( 3, $affiliate_rows, 'Edge growth must track actual links, not parents multiplied by merchants.' );
		self::assertTrue( $result['drift']['valid'] );
	}
}
