<?php

use Longevity\Core\Approval_Fingerprint;
use Longevity\Core\Approval_Repository;
use Longevity\Core\Approval_Service;
use Longevity\Core\Publication_Gates;
use PHPUnit\Framework\TestCase;

final class RegressionPublicationBypassTest extends TestCase {
	private int $postId = 99;
	private int $editorId = 10;

	protected function setUp(): void {
		$post = new WP_Post();
		$post->ID = $this->postId;
		$post->post_type = 'post';
		$post->post_title = 'Approved title';
		$post->post_excerpt = 'Approved excerpt';
		$post->post_content = 'Original approved content that passed editorial review.';
		$post->post_author = (string) $this->editorId;
		$GLOBALS['lel_test_posts'][ $this->postId ] = $post;
		$GLOBALS['lel_test_titles'][ $this->postId ] = 'Approved title';
		$GLOBALS['lel_test_page_statuses'][ $this->postId ] = 'publish';
		$GLOBALS['lel_test_permalinks'][ $this->postId ] = 'http://example.com/approved-post/';
		$GLOBALS['lel_test_current_user_id'] = $this->editorId;
		$GLOBALS['lel_test_user_caps'][ $this->editorId ] = array( 'approve_publication', 'edit_post' );
		$GLOBALS['lel_test_meta'][ $this->postId ] = array(
			'content_summary' => 'A concise test summary.',
			'content_limitations' => 'Test-specific limitations noted.',
			'next_content_review_date' => ( new DateTimeImmutable( '+180 days' ) )->format( 'Y-m-d' ),
			'commercial_relationship' => 'none',
			'editorial_approval_status' => 'ready',
			'material_health_claims' => '',
			'medical_review_required' => '',
			'testing_required' => '',
			'affiliate_links_present' => '',
			'original_contribution' => 'Original analysis approach.',
			'region_scope' => 'Global',
			'uncertainty_statement_present' => '1',
			'claim_count' => 0,
			'verified_claim_count' => 0,
			'source_count' => 0,
			'evidence_grade' => '',
		);
		$GLOBALS['lel_test_user_meta'][ $this->editorId ] = array();
		$GLOBALS['lel_test_editable_posts'] = array( $this->postId );
		$GLOBALS['lel_test_get_posts_result'] = array();
		$_POST = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['lel_test_posts'][ $this->postId ],
			$GLOBALS['lel_test_titles'][ $this->postId ],
			$GLOBALS['lel_test_page_statuses'][ $this->postId ],
			$GLOBALS['lel_test_permalinks'][ $this->postId ],
			$GLOBALS['lel_test_current_user_id'],
			$GLOBALS['lel_test_meta'][ $this->postId ],
			$GLOBALS['lel_test_user_caps'][ $this->editorId ],
			$GLOBALS['lel_test_user_meta'][ $this->editorId ],
			$GLOBALS['lel_test_editable_posts'],
			$GLOBALS['lel_test_transients'],
			$GLOBALS['lel_test_thumbnails'][ $this->postId ],
			$GLOBALS['lel_test_meta'][42],
			$GLOBALS['lel_test_meta'][301],
			$GLOBALS['lel_test_get_posts_result'],
			$GLOBALS['lel_test_posts'],
		);
		$_POST = array();
	}

	private function approveEditorial(): void {
		$snapshot = Approval_Service::approve( $this->postId, 'editorial', $this->editorId );
		self::assertNotNull( $snapshot, 'Precondition: editorial approval must be created.' );
		self::assertTrue( Approval_Service::is_current( $this->postId, 'editorial' ), 'Precondition: approval must be current.' );
	}

	public function test_content_change_with_publish_blocks_when_approval_now_stale(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_posts'][ $this->postId ]->post_content = 'Malicious content smuggled in a same-request publish.';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'After in-memory content mutation, the approval fingerprint must not match.' );
	}

	public function test_title_change_with_publish_blocks_when_approval_now_stale(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_posts'][ $this->postId ]->post_title = 'Replaced title with same request';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ) );
	}

	public function test_excerpt_change_with_publish_blocks_when_approval_now_stale(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_posts'][ $this->postId ]->post_excerpt = 'Replaced excerpt with same request';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ) );
	}

	public function test_author_change_with_publish_blocks_when_approval_now_stale(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_posts'][ $this->postId ]->post_author = '99';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ) );
	}

	public function test_content_change_triggers_full_invalidation_via_hooks(): void {
		$this->approveEditorial();
		$before = new WP_Post();
		$before->post_title = 'Approved title';
		$before->post_excerpt = 'Approved excerpt';
		$before->post_content = 'Original approved content that passed editorial review.';
		$before->post_author = (string) $this->editorId;
		$after = clone $before;
		$after->post_content = 'Mutated content intended to bypass approval.';
		Approval_Service::on_post_updated( $this->postId, $after, $before );
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'on_post_updated must invalidate editorial approval after content change.' );
		self::assertSame( 'stale', $GLOBALS['lel_test_meta'][ $this->postId ]['editorial_approval_status'] ?? '', 'Legacy status must project stale.' );
	}

	public function test_enforce_classic_publish_blocks_same_request_title_change(): void {
		$this->approveEditorial();
		$data = array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_title' => 'Title changed in same request',
			'post_content' => $GLOBALS['lel_test_posts'][ $this->postId ]->post_content,
		);
		$postarr = array( 'ID' => $this->postId );
		$result = Publication_Gates::enforce_classic_publish( $data, $postarr );
		self::assertSame( 'draft', $result['post_status'], 'Classic gate must downgrade publish to draft when title changes in the same request.' );
	}

	public function test_enforce_classic_publish_allows_slash_normalized_approved_content(): void {
		$this->approveEditorial();
		$data = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Approved title',
			'post_excerpt' => 'Approved excerpt',
			'post_content' => addslashes( $GLOBALS['lel_test_posts'][ $this->postId ]->post_content ),
			'post_author'  => (string) $this->editorId,
		);
		$result = Publication_Gates::enforce_classic_publish( $data, array( 'ID' => $this->postId ) );
		self::assertSame( 'publish', $result['post_status'], 'Classic gate must compare unslashed WordPress filter data with the approved state.' );
	}

	public function test_enforce_classic_publish_blocks_same_request_metadata_change(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_user_caps'][ $this->editorId ][] = 'edit_claims';
		$_POST['longevity_editorial_nonce'] = 'test_nonce_longevity_save_editorial';
		$_POST['lel_present'] = array( 'evidence_grade' => '1' );
		$_POST['evidence_grade'] = 'A';
		$data = array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_content' => $GLOBALS['lel_test_posts'][ $this->postId ]->post_content,
		);
		$postarr = array( 'ID' => $this->postId );
		$result = Publication_Gates::enforce_classic_publish( $data, $postarr );
		self::assertSame( 'draft', $result['post_status'], 'Classic gate must block same-request metadata+status change.' );
	}

	public function test_enforce_classic_publish_blocks_same_request_featured_image_change(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_thumbnails'][ $this->postId ] = 42;
		$GLOBALS['lel_test_meta'][42]['_wp_attachment_image_alt'] = 'New image';
		$_POST['_thumbnail_id'] = '42';
		$result = Publication_Gates::enforce_classic_publish(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Approved title',
				'post_content'=> $GLOBALS['lel_test_posts'][ $this->postId ]->post_content,
			),
			array( 'ID' => $this->postId )
		);
		self::assertSame( 'draft', $result['post_status'], 'A featured-image change must not promote the changed revision.' );
	}

	public function test_evaluate_with_content_override_does_not_use_old_approval(): void {
		$this->approveEditorial();
		$overrides = array( 'content' => 'Entirely new body smuggled with publish request.' );
		$result = Publication_Gates::evaluate( $this->postId, $overrides );
		$codes = array_column( $result->blocking(), 'code' );
		self::assertContains( 'editorial_approval_stale', $codes, 'evaluate() should recognize that the new override content makes the old approval stale.' );
	}

	public function test_evaluate_with_content_override_is_accurate_when_overrides_match_reality(): void {
		$overrides = array( 'content' => $GLOBALS['lel_test_posts'][ $this->postId ]->post_content );
		$before_approval = Approval_Service::is_current( $this->postId, 'editorial' );
		$result = Publication_Gates::evaluate( $this->postId, $overrides );
		self::assertSame( $before_approval, ! in_array( 'editorial_approval_stale', array_column( $result->blocking(), 'code' ), true ), 'Approval staleness should match even when overrides duplicate reality.' );
	}

	public function test_rest_enforce_blocks_same_request_meta_with_publish(): void {
		$this->approveEditorial();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->postId );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'meta', array( 'evidence_grade' => 'B', 'content_summary' => 'Bypass attempt' ) );
		$prepared = new WP_Post();
		$prepared->post_content = $GLOBALS['lel_test_posts'][ $this->postId ]->post_content;
		$prepared->post_status = 'publish';
		$response = Publication_Gates::enforce_rest_publish( $prepared, $request );
		self::assertInstanceOf( WP_Error::class, $response, 'REST gate must block same-request metadata change with publish status.' );
	}

	public function test_rest_enforce_blocks_same_request_content_change_with_publish(): void {
		$this->approveEditorial();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->postId );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'id', $this->postId );
		$prepared = new WP_Post();
		$prepared->post_content = 'REST-originated malicious content.';
		$prepared->post_status = 'publish';
		$response = Publication_Gates::enforce_rest_publish( $prepared, $request );
		self::assertInstanceOf( WP_Error::class, $response, 'REST gate must block same-request content change with publish status.' );
	}

	public function test_rest_status_request_cannot_be_hidden_by_prepared_draft_status(): void {
		$this->approveEditorial();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->postId );
		$request->set_param( 'id', $this->postId );
		$request->set_param( 'status', 'publish' );
		$prepared = new WP_Post();
		$prepared->post_title = 'Changed while prepared as draft';
		$prepared->post_content = $GLOBALS['lel_test_posts'][ $this->postId ]->post_content;
		$prepared->post_status = 'draft';
		$response = Publication_Gates::enforce_rest_publish( $prepared, $request );
		self::assertInstanceOf( WP_Error::class, $response, 'The requested public status must be gated even when preparation reports draft.' );
	}

	public function test_rest_enforce_blocks_first_time_publish_without_revision(): void {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/0' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'id', 0 );
		$prepared = new WP_Post();
		$prepared->post_content = 'New post content.';
		$prepared->post_status = 'publish';
		$response = Publication_Gates::enforce_rest_publish( $prepared, $request );
		self::assertInstanceOf( WP_Error::class, $response, 'First-time publish without saved draft must fail.' );
	}

	public function test_same_request_claim_change_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_meta'][ $this->postId ]['material_health_claims'] = '1';
		$GLOBALS['lel_test_meta'][ $this->postId ]['claim_count'] = 2;
		$GLOBALS['lel_test_meta'][ $this->postId ]['verified_claim_count'] = 0;
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'Adding unverified claims must stale editorial approval without requiring a separate request.' );
	}

	public function test_dependency_change_invalidates_exact_approval(): void {
		$this->approveEditorial();
		$claim = new WP_Post();
		$claim->ID = 301;
		$claim->post_type = 'lel_claim';
		$GLOBALS['lel_test_posts'][301] = $claim;
		$GLOBALS['lel_test_meta'][301] = array(
			'post_id'            => $this->postId,
			'claim_id'           => 'CL-301',
			'claim_text'         => 'A newly linked claim.',
			'verification_status' => 'unverified',
		);
		$GLOBALS['lel_test_get_posts_result'] = array( $claim );
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'A newly linked dependency must not remain covered by the old approval.' );
	}

	public function test_same_request_commercial_relationship_change_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_meta'][ $this->postId ]['commercial_relationship'] = 'affiliate';
		$GLOBALS['lel_test_meta'][ $this->postId ]['affiliate_links_present'] = '1';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'Changing to an affiliate relationship must stale editorial approval.' );
	}

	public function test_same_request_featured_image_change_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_posts'][ $this->postId ]->post_content = $GLOBALS['lel_test_posts'][ $this->postId ]->post_content . '<!-- wp:image --><figure class="wp-block-image"><img src="http://example.com/new-image.jpg" alt=""/></figure><!-- /wp:image -->';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'Adding content with a new featured image must stale approval.' );
	}

	public function test_same_request_evidence_grade_change_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_meta'][ $this->postId ]['evidence_grade'] = 'A';
		$GLOBALS['lel_test_meta'][ $this->postId ]['evidence_grade_rationale'] = 'Strong evidence from multiple RCTs.';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'Adding an evidence grade after editorial approval must stale the approval.' );
	}

	public function test_enforce_classic_publish_blocks_future_status_too(): void {
		$this->approveEditorial();
		$data = array(
			'post_type' => 'post',
			'post_status' => 'future',
			'post_title' => 'Scheduled content changed same-request',
			'post_content' => $GLOBALS['lel_test_posts'][ $this->postId ]->post_content,
		);
		$postarr = array( 'ID' => $this->postId );
		$result = Publication_Gates::enforce_classic_publish( $data, $postarr );
		self::assertSame( 'draft', $result['post_status'], 'Classic gate must also block same-request changes with future status.' );
	}

	public function test_enforce_classic_publish_blocks_private_status_too(): void {
		$this->approveEditorial();
		$data = array(
			'post_type' => 'post',
			'post_status' => 'private',
			'post_title' => 'Private post changed same-request',
			'post_content' => $GLOBALS['lel_test_posts'][ $this->postId ]->post_content,
		);
		$postarr = array( 'ID' => $this->postId );
		$result = Publication_Gates::enforce_classic_publish( $data, $postarr );
		self::assertSame( 'draft', $result['post_status'], 'Classic gate must also block same-request changes with private status.' );
	}

	public function test_same_request_region_and_scope_change_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_meta'][ $this->postId ]['region_scope'] = 'EU-only';
		$GLOBALS['lel_test_meta'][ $this->postId ]['original_contribution'] = 'Replaced contribution';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'Region and scope change must stale editorial approval.' );
	}

	public function test_same_request_original_contribution_change_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_meta'][ $this->postId ]['original_contribution'] = 'Changed contribution without changing scope.';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ) );
	}

	public function test_same_request_medical_field_change_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_meta'][ $this->postId ]['medical_review_required'] = '1';
		$GLOBALS['lel_test_meta'][ $this->postId ]['medical_reviewer_user_id'] = '5';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'medical' ), 'Adding medical fields must stale medical approval.' );
	}

	public function test_same_request_testing_field_change_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_meta'][ $this->postId ]['testing_required'] = '1';
		$GLOBALS['lel_test_meta'][ $this->postId ]['testing_protocol_version'] = '2.0';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'testing' ), 'Adding testing fields must stale testing approval.' );
	}

	public function test_mixed_allowed_and_denied_metadata_in_same_request_blocks(): void {
		$this->approveEditorial();
		$GLOBALS['lel_test_posts'][ $this->postId ]->post_title = 'Changed title in mixed attack';
		$GLOBALS['lel_test_meta'][ $this->postId ]['evidence_grade'] = 'B';
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ), 'Mixed allowed+denied metadata change must stale editorial approval.' );
	}
}
