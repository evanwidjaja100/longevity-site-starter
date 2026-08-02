<?php

use Longevity\Core\Trust_Pages;
use PHPUnit\Framework\TestCase;

final class TrustPagesTest extends TestCase {
	protected function tearDown(): void {
		$GLOBALS['lel_test_posts'] = array();
	}

	public function test_unresolved_markers_are_detected(): void {
		self::assertTrue( Trust_Pages::has_unresolved_markers( '**Last reviewed:** [date]' ) );
		self::assertTrue( Trust_Pages::has_unresolved_markers( 'TODO: legal must confirm this' ) );
		self::assertTrue( Trust_Pages::has_unresolved_markers( 'Some Lorem Ipsum filler' ) );
		self::assertTrue( Trust_Pages::has_unresolved_markers( '[PLACEHOLDER for counsel]' ) );
		self::assertFalse( Trust_Pages::has_unresolved_markers( 'We review every article before publication.' ) );
	}

	public function test_trust_slugs_are_recognized(): void {
		self::assertTrue( Trust_Pages::is_trust_slug( 'privacy' ) );
		self::assertTrue( Trust_Pages::is_trust_slug( 'ai-assisted-work-disclosure' ) );
		self::assertFalse( Trust_Pages::is_trust_slug( 'start-here' ) );
	}

	public function test_non_trust_page_publish_passes_through(): void {
		$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'start-here', 'post_content' => 'x' );
		$result = Trust_Pages::enforce_publication_gate( $data, array( 'ID' => 5 ) );
		self::assertSame( 'publish', $result['post_status'] );
	}

	public function test_new_trust_page_cannot_publish_directly(): void {
		$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'privacy', 'post_content' => 'Complete policy.' );
		$result = Trust_Pages::enforce_publication_gate( $data, array() );
		self::assertSame( 'draft', $result['post_status'] );
	}

	public function test_trust_page_with_unresolved_markers_cannot_publish(): void {
		$GLOBALS['lel_test_posts'][9] = (object) array( 'ID' => 9, 'post_type' => 'page', 'post_name' => 'privacy', 'post_content' => 'Policy [date]' );
		$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'privacy', 'post_content' => 'Policy [date]' );
		$result = Trust_Pages::enforce_publication_gate( $data, array( 'ID' => 9 ) );
		self::assertSame( 'draft', $result['post_status'] );
	}

	public function test_unapproved_trust_page_cannot_publish(): void {
		$GLOBALS['lel_test_posts'][9] = (object) array( 'ID' => 9, 'post_type' => 'page', 'post_name' => 'privacy', 'post_content' => 'Complete policy.' );
		$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'privacy', 'post_content' => 'Complete policy.' );
		$result = Trust_Pages::enforce_publication_gate( $data, array( 'ID' => 9 ) );
		self::assertSame( 'draft', $result['post_status'] );
	}
}
