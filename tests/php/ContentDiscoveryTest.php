<?php

use Longevity\Core\Content_Discovery;
use PHPUnit\Framework\TestCase;

final class ContentDiscoveryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_is_admin'] = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_is_admin'] );
		$_GET = array();
	}

	public function test_does_not_filter_admin_queries(): void {
		$GLOBALS['lel_test_is_admin'] = true;
		$query = new WP_Query();
		$query->query_vars = array();

		Content_Discovery::filter_main_query( $query );

		self::assertEmpty( $query->query_vars );
	}

	public function test_does_not_filter_non_main_query(): void {
		$query = new WP_Query();
		$query->_is_main_query = false;
		$query->query_vars = array();

		Content_Discovery::filter_main_query( $query );

		self::assertEmpty( $query->query_vars );
	}

	public function test_search_sets_post_type_to_both_by_default(): void {
		$query = new WP_Query();
		$query->_is_search = true;

		Content_Discovery::filter_main_query( $query );

		self::assertSame( array( 'post', 'review' ), $query->query_vars['post_type'] );
		self::assertSame( 10, $query->query_vars['posts_per_page'] );
	}

	public function test_search_with_content_type_guide_sets_post_type_to_post(): void {
		$_GET['content_type'] = 'guide';
		$query = new WP_Query();
		$query->_is_search = true;

		Content_Discovery::filter_main_query( $query );

		self::assertSame( 'post', $query->query_vars['post_type'] );
	}

	public function test_search_with_content_type_review_sets_post_type_to_review(): void {
		$_GET['content_type'] = 'review';
		$query = new WP_Query();
		$query->_is_search = true;

		Content_Discovery::filter_main_query( $query );

		self::assertSame( 'review', $query->query_vars['post_type'] );
	}

	public function test_search_invalid_content_type_falls_back_to_all(): void {
		$_GET['content_type'] = 'invalid_type';
		$query = new WP_Query();
		$query->_is_search = true;

		Content_Discovery::filter_main_query( $query );

		self::assertSame( array( 'post', 'review' ), $query->query_vars['post_type'] );
	}

	public function test_search_with_sort_newest_sets_orderby(): void {
		$_GET['sort'] = 'newest';
		$query = new WP_Query();
		$query->_is_search = true;

		Content_Discovery::filter_main_query( $query );

		self::assertSame( array( 'date' => 'DESC', 'ID' => 'DESC' ), $query->query_vars['orderby'] );
	}

	public function test_search_with_sort_updated_sets_orderby_modified(): void {
		$_GET['sort'] = 'updated';
		$query = new WP_Query();
		$query->_is_search = true;

		Content_Discovery::filter_main_query( $query );

		self::assertSame( array( 'modified' => 'DESC', 'ID' => 'DESC' ), $query->query_vars['orderby'] );
	}

	public function test_category_archive_sets_post_type(): void {
		$query = new WP_Query();
		$query->_is_category = true;

		Content_Discovery::filter_main_query( $query );

		self::assertSame( array( 'post', 'review' ), $query->query_vars['post_type'] );
	}

	public function test_home_page_sets_post_type(): void {
		$query = new WP_Query();
		$query->_is_home = true;

		Content_Discovery::filter_main_query( $query );

		self::assertSame( array( 'post', 'review' ), $query->query_vars['post_type'] );
	}
}
