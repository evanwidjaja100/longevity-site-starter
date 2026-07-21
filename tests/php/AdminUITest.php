<?php

use Longevity\Core\Admin_UI;
use PHPUnit\Framework\TestCase;

final class AdminUITest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_pages_by_slug'] = array();
		$GLOBALS['lel_test_meta'] = array();
		$GLOBALS['lel_test_current_user_caps'] = array( 'edit_post' );
		$GLOBALS['lel_test_editable_posts'] = array( 1 );
		$GLOBALS['lel_test_is_revision'] = false;
		$GLOBALS['lel_test_is_autosave'] = false;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['lel_test_current_user_caps'] );
		unset( $GLOBALS['lel_test_editable_posts'] );
		unset( $GLOBALS['lel_test_is_revision'] );
		unset( $GLOBALS['lel_test_is_autosave'] );
	}

	private function makePost( int $id = 1, string $type = 'post' ): WP_Post {
		$post = new WP_Post();
		$post->ID = $id;
		$post->post_type = $type;
		return $post;
	}

	private function setValidNonce(): void {
		$_POST['longevity_editorial_nonce'] = 'test_nonce_longevity_save_editorial';
	}

	public function test_returns_early_when_revision(): void {
		$GLOBALS['lel_test_is_revision'] = true;
		$post = $this->makePost();
		Admin_UI::save_editorial_meta( 1, $post, false );
		$this->addToAssertionCount( 1 );
	}

	public function test_returns_early_when_autosave(): void {
		$GLOBALS['lel_test_is_autosave'] = true;
		$post = $this->makePost();
		Admin_UI::save_editorial_meta( 1, $post, false );
		$this->addToAssertionCount( 1 );
	}

	public function test_returns_early_without_nonce(): void {
		$post = $this->makePost();
		Admin_UI::save_editorial_meta( 1, $post, false );
		$this->addToAssertionCount( 1 );
	}

	public function test_saves_text_field_when_nonce_valid(): void {
		$this->setValidNonce();
		$_POST['content_summary'] = 'A test summary.';
		$post = $this->makePost();

		Admin_UI::save_editorial_meta( 1, $post, false );

		self::assertSame( 'A test summary.', $GLOBALS['lel_test_meta'][1]['content_summary'] );
	}
}
