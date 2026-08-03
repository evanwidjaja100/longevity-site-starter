<?php
/**
 * Idempotent synthetic content for local browser and CI tests only.
 *
 * All identities, products, sources, and URLs are explicitly synthetic and use
 * reserved example domains. This file must never be used for production data.
 *
 * @package LongevityCore
 */

$lel_fixture_environment = wp_get_environment_type();
if ( ! in_array( $lel_fixture_environment, array( 'local', 'development' ), true ) ) {
	WP_CLI::error( sprintf( 'Synthetic fixtures refuse to run in the "%s" environment. Only local and development environments may host the CI fixture projection; staging and production must never contain synthetic records.', $lel_fixture_environment ) );
}

$today       = gmdate( 'Y-m-d' );
$next_review = gmdate( 'Y-m-d', strtotime( '+180 days' ) );
$admin_id    = get_current_user_id();

$author = get_user_by( 'login', 'lel_test_author' );
if ( ! $author ) {
	$author_id = wp_create_user( 'lel_test_author', wp_generate_password( 32, true, true ), 'author@example.invalid' );
	if ( is_wp_error( $author_id ) ) {
		WP_CLI::error( $author_id->get_error_message() );
	}
	$author = get_user_by( 'id', $author_id );
}
$author->set_role( 'lel_writer' );
wp_update_user(
	array(
		'ID'           => $author->ID,
		'display_name' => '[TEST] Synthetic Author',
		'description'  => 'Synthetic author profile for local and CI route testing only.',
	)
);

/**
 * Find or create a synthetic actor and grant only the requested fixture capabilities.
 *
 * @param string $login        Synthetic user login.
 * @param string $email        Synthetic email on a reserved example domain.
 * @param string $display_name Clearly labeled synthetic display name.
 * @param string $role         Role slug to assign.
 * @param array  $capabilities Additional capabilities to grant.
 * @return WP_User The found or created synthetic user.
 */
function lel_fixture_user( string $login, string $email, string $display_name, string $role, array $capabilities = array() ): WP_User {
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		$user_id = wp_create_user( $login, wp_generate_password( 32, true, true ), $email );
		if ( is_wp_error( $user_id ) ) {
			WP_CLI::error( $user_id->get_error_message() );
		}
		$user = get_user_by( 'id', $user_id );
	}
	$user->set_role( $role );
	foreach ( $capabilities as $capability ) {
		$user->add_cap( $capability );
	}
	wp_update_user(
		array(
			'ID'           => $user->ID,
			'display_name' => $display_name,
		)
	);
	return $user;
}

$fact_checker        = lel_fixture_user( 'lel_synthetic_fact_checker', 'fact-checker@example.invalid', '[TEST] Synthetic Fact Checker', 'lel_fact_checker' );
$tester              = lel_fixture_user( 'lel_synthetic_tester', 'tester@example.invalid', '[TEST] Synthetic Product Tester', 'lel_product_tester' );
$test_approver       = lel_fixture_user( 'lel_synthetic_test_approver', 'test-approver@example.invalid', '[TEST] Synthetic Test Approver', 'subscriber', array( 'approve_test_records' ) );
$commercial_approver = lel_fixture_user( 'lel_synthetic_commercial_approver', 'commercial-approver@example.invalid', '[TEST] Synthetic Commercial Approver', 'subscriber', array( 'approve_commercial_disclosure' ) );

/**
 * Find or create a named post.
 *
 * @param string $type      Post type slug.
 * @param string $slug      Post slug used for idempotent lookup.
 * @param string $title     Post title.
 * @param string $content   Post content.
 * @param int    $author_id Author user ID.
 * @return int The created or updated post ID.
 */
function lel_fixture_post( string $type, string $slug, string $title, string $content, int $author_id ): int {
	$found   = get_posts(
		array(
			'post_type'      => $type,
			'post_status'    => 'any',
			'name'           => $slug,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	$post_id = empty( $found ) ? 0 : (int) $found[0];
	$data    = array(
		'ID'           => $post_id,
		'post_type'    => $type,
		'post_status'  => 'draft',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
		'post_excerpt' => 'Synthetic local/CI fixture used to verify the public experience and governance controls.',
		'post_author'  => $author_id,
	);
	$result  = $post_id ? wp_update_post( $data, true ) : wp_insert_post( $data, true );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result->get_error_message() );
	}
	return (int) $result;
}

/**
 * Apply a metadata map.
 *
 * @param int   $post_id Target post ID.
 * @param array $values  Map of meta keys to values.
 */
function lel_fixture_meta( int $post_id, array $values ): void {
	\Longevity\Core\Meta_Authorization::enter_trusted_scope();
	try {
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	} finally {
		\Longevity\Core\Meta_Authorization::exit_trusted_scope();
	}
}

/**
 * Common publication metadata for synthetic public fixtures.
 *
 * @param string $today       Current UTC date in Y-m-d format.
 * @param string $next_review Next scheduled content-review date in Y-m-d format.
 * @return array Publication metadata map.
 */
function lel_fixture_public_meta( string $today, string $next_review ): array {
	return array(
		'content_summary'               => 'A synthetic page for exercising editorial metadata and public trust components.',
		'content_scope'                 => 'Local and CI rendering behavior only.',
		'content_limitations'           => 'This is test data, not health guidance, product advice, or real evidence.',
		'original_contribution'         => 'Automated verification of templates, structured metadata, and publication controls.',
		'commercial_relationship'       => 'none',
		'affiliate_disclosure_status'   => 'not_required',
		'editorial_approval_status'     => 'editorial_review',
		'correction_status'             => 'none',
		'last_material_update'          => $today,
		'next_content_review_date'      => $next_review,
		'region_scope'                  => 'Synthetic test environment',
		'uncertainty_statement_present' => true,
	);
}

/**
 * Create or refresh a content approval through the same immutable service used in production.
 *
 * @param int    $post_id  Target post ID.
 * @param string $type     Approval type slug.
 * @param int    $actor_id Approving user ID.
 * @param array  $payload  Optional approval payload.
 */
function lel_fixture_approve( int $post_id, string $type, int $actor_id, array $payload = array() ): void {
	if ( \Longevity\Core\Approval_Service::is_current( $post_id, $type ) ) {
		$projection = array(
			'editorial'  => array( 'editorial_approval_status' => 'ready' ),
			'fact_check' => array(
				'fact_check_status' => 'complete',
				'fact_checked_by'   => $actor_id,
				'fact_checked_date' => gmdate( 'Y-m-d' ),
			),
			'medical'    => array(
				'medical_review_status'   => 'complete',
				'medical_review_attested' => true,
				'medical_review_date'     => gmdate( 'Y-m-d' ),
			),
			'testing'    => array( 'testing_status' => 'approved' ),
			'commercial' => array( 'affiliate_disclosure_status' => 'approved' ),
		);
		lel_fixture_meta( $post_id, $projection[ $type ] ?? array() );
		return;
	}
	if ( ! \Longevity\Core\Approval_Service::approve( $post_id, $type, $actor_id, $payload ) ) {
		WP_CLI::error( sprintf( 'Synthetic %s approval failed for post %d.', $type, $post_id ) );
	}
}

$category = get_term_by( 'slug', 'evidence-literacy', 'category' );
if ( ! $category ) {
	$created  = wp_insert_term( 'Evidence Literacy', 'category', array( 'slug' => 'evidence-literacy' ) );
	$category = is_wp_error( $created ) ? null : get_term( (int) $created['term_id'], 'category' );
}

$article_content = <<<'HTML'
<!-- wp:paragraph {"className":"longevity-fixture-notice"} --><p class="longevity-fixture-notice"><strong>Synthetic test content:</strong> this page exists only in local and CI environments. It is not evidence or health advice.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>What this test guide checks</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>This guide checks that long-form editorial content remains readable, predictable, and transparent across common screen sizes. It exercises the article header, metadata, trust summary, table of contents, section anchors, source list, correction history, related content, and footer. Every statement on this page describes software behavior, not human health. The deliberately plain language also helps automated checks find a stable reading order without relying on decorative text or hidden labels.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>How the synthetic evidence label works</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>The evidence label attached to this record is test data. Its rationale says exactly that, and its source uses an example domain reserved for documentation. The public renderer should expose the grade as text, explain the uncertainty, and avoid implying that a color alone communicates meaning. A source should appear only when its linked claim is verified and contains bounded public bibliographic fields. Private editorial notes must never be printed into the page.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Reading order and navigation</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>The document has one primary heading followed by sequential section headings. A generated table of contents appears because the article is long enough and contains several sections. Each link points to a stable, unique identifier. Keyboard users should be able to enter through the skip link, continue through the article controls, and reach the correction and related-content areas without a focus trap. Zooming or narrowing the viewport should not hide essential information.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Trust information</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>The trust summary separates publication dates, evidence context, fact-check state, medical-review state, testing state, and commercial relationships. An absent review should be described honestly instead of being framed as a credential. Synthetic records carry conspicuous labels. No real professional identity, institution, product, study, price, outcome, or recommendation is represented here. That boundary is important because development data should not accidentally become public authority.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Resilient empty states</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Some components intentionally have little data. The interface should omit empty sections or explain what is unavailable without exposing database keys. Search and archive views should offer a useful recovery path. A review archive with no matching filter should not invent a recommendation. Related content should stay bounded and should not repeat the current record. These decisions keep a sparse staging site understandable while preserving room for future verified material.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Limits of this fixture</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Automated checks cannot establish editorial truth, clinical accuracy, legal compliance, or the validity of a professional credential. They can verify markup, permissions, state transitions, sanitization, accessibility rules, and performance budgets. Human review remains required for real publication. A passing fixture therefore means that the implementation behaves as designed under known inputs; it does not certify any real-world content or operational process.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Performance results also depend on the runtime, network, cache, browser, and installed extensions. The local fixture controls only a small part of that environment. Production monitoring must use representative devices and real traffic while respecting consent and privacy. A laboratory score is a release signal, not a promise that every visit will have the same timing.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Finally, this record is deliberately easy to identify and remove. Its slug, title, accounts, email domains, claims, and product names all carry test markers. The production launch checklist requires deleting synthetic records and rerunning the public crawl before search visibility is enabled.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Additional checks cover responsive images, readable line lengths, high-contrast focus indicators, reduced-motion preferences, print output, and forced-color modes. They also verify that a missing image does not leave an empty visual frame, that dates use machine-readable values, and that linked citations use meaningful labels. These are narrow implementation assertions that can be repeated reliably on every change.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>The governance layer is exercised separately from presentation. Its synthetic complete records must pass, incomplete records must stay unpublished, and unsupported schema must remain absent. Migration and freshness jobs are bounded and idempotent. Analytics accepts only documented event fields and does not forward events until the relevant consent state is active. Together, these fixtures provide a compact regression surface without claiming to validate real evidence.</p><!-- /wp:paragraph -->
HTML;

$article_id = lel_fixture_post( 'post', 'test-evidence-guide', '[TEST] Evidence guide rendering', $article_content, $author->ID );
lel_fixture_meta(
	$article_id,
	array_merge(
		lel_fixture_public_meta( $today, $next_review ),
		array(
			'evidence_grade'           => 'U',
			'evidence_grade_rationale' => 'Unrated synthetic data used only to test the evidence-grade interface.',
			'evidence_cutoff_date'     => $today,
			'fact_check_status'        => 'not_required',
			'medical_review_status'    => 'not_required',
			'testing_status'           => 'not_required',
		)
	)
);
if ( $category ) {
	wp_set_post_terms( $article_id, array( (int) $category->term_id ), 'category' );
}
wp_set_post_terms( $article_id, array( 'synthetic-test', 'evidence-interface' ), 'post_tag' );

$claim_id = lel_fixture_post( 'lel_claim', 'test-interface-claim', '[TEST] Interface claim', '', $admin_id );
lel_fixture_meta(
	$claim_id,
	array(
		'post_id'             => $article_id,
		'claim_id'            => 'TEST-INTERFACE-001',
		'claim_text'          => 'This synthetic claim exists only to exercise the verified-source renderer.',
		'claim_category'      => 'test',
		'claim_importance'    => 'low',
		'source_type'         => 'Synthetic test record',
		'source_title'        => 'Reserved example source for interface testing',
		'source_authors'      => 'Automated test fixture',
		'source_url'          => 'https://example.invalid/test-source',
		'source_identifier'   => 'TEST-SOURCE-001',
		'publication_date'    => $today,
		'accessed_date'       => $today,
		'evidence_grade'      => 'U',
		'verification_status' => 'not_verified',
		'recheck_date'        => $next_review,
	)
);
if ( ! \Longevity\Core\Claims::verify( $claim_id, $fact_checker->ID ) ) {
	WP_CLI::error( 'Synthetic interface claim could not be independently verified.' );
}

$reviewer = lel_fixture_user( 'lel_synthetic_reviewer', 'reviewer@example.invalid', '[TEST] Synthetic Reviewer', 'lel_medical_reviewer' );
update_user_meta( $reviewer->ID, 'professional_credentials', 'Synthetic credential for automated interface testing only; not a real clinician.' );
update_user_meta( $reviewer->ID, 'review_scope', 'full_article' );
update_user_meta( $reviewer->ID, 'jurisdictions', 'Synthetic test environment' );
update_user_meta( $reviewer->ID, 'conflict_disclosure', 'Synthetic identity; no real-world professional relationship.' );
if ( ! \Longevity\Core\Reviewer_Credentials::is_valid_for( $reviewer->ID, 'full_article', 'Synthetic test environment', $today ) ) {
	$verified = \Longevity\Core\Reviewer_Credentials::verify(
		$reviewer->ID,
		array(
			'credential_verification_date'         => $today,
			'credential_expiration_date'           => gmdate( 'Y-m-d', strtotime( '+365 days' ) ),
			'credential_verification_evidence_ref' => 'synthetic-ci-reference',
			'verified_professional_credentials'    => 'Synthetic credential for automated interface testing only; not a real clinician.',
			'verified_review_scope'                => 'full_article',
			'verified_jurisdictions'               => 'Synthetic test environment',
		),
		$admin_id
	);
	if ( ! $verified ) {
		WP_CLI::error( 'Synthetic reviewer credentials could not be independently verified.' );
	}
}

$medical_id       = lel_fixture_post( 'post', 'test-medically-reviewed-article', '[TEST] Medical review workflow', $article_content, $author->ID );
$medical_claim_id = lel_fixture_post( 'lel_claim', 'test-medical-claim', '[TEST] Medical workflow claim', '', $admin_id );
lel_fixture_meta(
	$medical_claim_id,
	array(
		'post_id'             => $medical_id,
		'claim_id'            => 'TEST-MEDICAL-001',
		'claim_text'          => 'Synthetic statement used only to exercise medical-review gating.',
		'source_type'         => 'Synthetic test record',
		'source_title'        => 'Reserved example source for medical workflow testing',
		'source_url'          => 'https://example.invalid/test-medical-source',
		'verification_status' => 'not_verified',
	)
);
if ( ! \Longevity\Core\Claims::verify( $medical_claim_id, $fact_checker->ID ) ) {
	WP_CLI::error( 'Synthetic medical claim could not be independently verified.' );
}
lel_fixture_meta(
	$medical_id,
	array_merge(
		lel_fixture_public_meta( $today, $next_review ),
		array(
			'material_health_claims'         => true,
			'fact_check_status'              => 'in_progress',
			'next_fact_check_date'           => $next_review,
			'medical_review_required'        => true,
			'medical_review_status'          => 'assigned',
			'medical_reviewer_user_id'       => $reviewer->ID,
			'medical_review_scope'           => 'full_article',
			'medical_review_sections'        => 'All synthetic sections.',
			'medical_review_limitations'     => 'No real medical assertions were reviewed.',
			'medical_review_conflicts'       => 'Synthetic identity; no real conflicts.',
			'medical_review_revision_status' => 'not_applicable',
			'next_medical_review_date'       => $next_review,
			'medical_review_version'         => 'test-1.0',
			'medical_review_attested'        => false,
			'testing_status'                 => 'not_required',
		)
	)
);

$protocol_id = lel_fixture_post( 'lel_protocol', 'test-wearable-protocol', '[TEST] Wearable protocol', '', $tester->ID );
lel_fixture_meta(
	$protocol_id,
	array(
		'protocol_id'                       => 'TEST-WEARABLE',
		'protocol_version'                  => '1.0',
		'product_category'                  => 'Synthetic wearable',
		'effective_date'                    => '2025-01-01',
		'minimum_test_duration'             => 'Three synthetic sessions',
		'required_observations'             => 'Rendering and state checks only.',
		'required_comparison_methods'       => 'Synthetic comparison fixture.',
		'required_environmental_conditions' => 'Local or CI runtime.',
		'required_disclosure_fields'        => 'Synthetic product and acquisition labels.',
		'scoring_dimensions'                => array(
			array(
				'name'   => 'Interface verification',
				'score'  => 4.0,
				'weight' => 100.0,
			),
		),
		'known_limitations'                 => 'No physical product was tested.',
		'approval_status'                   => 'pending',
	)
);
if ( ! \Longevity\Core\Review_Methodology::approve_protocol( $protocol_id, $test_approver->ID ) ) {
	WP_CLI::error( 'Synthetic protocol could not be independently approved.' );
}

$record_id = lel_fixture_post( 'lel_test_record', 'test-wearable-record', '[TEST] Wearable test record', '', $tester->ID );
lel_fixture_meta(
	$record_id,
	array(
		'product_name'          => 'Example Device TEST-1',
		'unit_identifier'       => 'SYNTHETIC-UNIT-001',
		'acquisition_method'    => 'purchased',
		'tester_user_ids'       => (string) $tester->ID,
		'submitted_by'          => $tester->ID,
		'submitted_at'          => gmdate( DATE_ATOM ),
		'test_start_date'       => '2025-02-01',
		'test_end_date'         => '2025-02-03',
		'protocol_id'           => 'TEST-WEARABLE',
		'protocol_version'      => '1.0',
		'raw_observations'      => 'Synthetic observations for template verification only.',
		'measurement_equipment' => 'No physical equipment; software fixture.',
		'failures'              => 'None recorded in the synthetic run.',
		'deviations'            => 'Physical testing not applicable.',
		'comparison_devices'    => 'Example Comparator TEST-2.',
		'environment'           => 'Local Docker environment.',
		'evidence_references'   => 'https://example.invalid/test-method',
		'public_test_results'   => array(
			array(
				'label'           => 'Battery duration',
				'observed_value'  => '6.2',
				'unit'            => 'days',
				'reference_label' => 'Synthetic reference',
				'reference_value' => '7 days',
				'status'          => 'partially_meets',
				'note'            => 'Synthetic observation for interface testing only.',
				'display_order'   => 10,
			),
			array(
				'label'           => 'Data export',
				'observed_value'  => 'Available',
				'unit'            => '',
				'reference_label' => '',
				'reference_value' => '',
				'status'          => 'informational',
				'note'            => 'No real account or export was used.',
				'display_order'   => 20,
			),
		),
		'conflicts'             => 'Synthetic test data only.',
		'approval_status'       => 'pending',
	)
);
if ( ! \Longevity\Core\Review_Methodology::valid_test_record( $record_id, '1.0' ) && ! \Longevity\Core\Review_Methodology::approve_test_record( $record_id, $test_approver->ID ) ) {
	WP_CLI::error( 'Synthetic test record could not be independently approved.' );
}

$review_content = '<!-- wp:paragraph --><p><strong>Synthetic product review:</strong> no physical product, purchase, endorsement, or recommendation is represented.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Decision context</h2><!-- /wp:heading --><p>This local fixture verifies that the decision summary appears before commercial actions and that score confidence remains distinct from the score.</p><!-- wp:heading --><h2>Method</h2><!-- /wp:heading --><p>The linked approved record and matching protocol version are synthetic. The interface must say what was and was not tested.</p><!-- wp:heading --><h2>Limitations</h2><!-- /wp:heading --><p>No physical performance, price, durability, accuracy, or health outcome was measured.</p>';
$review_id      = lel_fixture_post( 'review', 'test-valid-review', '[TEST] Valid review workflow', $review_content, $author->ID );
$dimensions     = array(
	array(
		'name'   => 'Interface clarity',
		'score'  => 4.0,
		'weight' => 50.0,
	),
	array(
		'name'   => 'Metadata completeness',
		'score'  => 3.6,
		'weight' => 50.0,
	),
);
lel_fixture_meta(
	$review_id,
	array_merge(
		lel_fixture_public_meta( $today, $next_review ),
		array(
			'testing_required'           => true,
			'testing_status'             => 'complete',
			'testing_start_date'         => '2025-02-01',
			'testing_end_date'           => '2025-02-03',
			'testing_duration'           => 'Three synthetic sessions',
			'testing_methodology_url'    => 'https://example.invalid/test-method',
			'testing_protocol_version'   => '1.0',
			'test_record_id'             => $record_id,
			'product_acquisition_method' => 'purchased',
			'review_score'               => 3.8,
			'review_score_version'       => \Longevity\Core\Review_Methodology::model_version(),
			'review_score_confidence'    => 'Low confidence',
			'review_score_dimensions'    => $dimensions,
			'best_for'                   => 'Testing the complete review interface.',
			'not_for'                    => 'Any real purchase or health decision.',
			'tested_product_model'       => 'Example Device TEST-1',
			'product_brand'              => '[TEST] Example Labs',
			'product_variant'            => 'Synthetic blue',
			'product_price_amount'       => 199.00,
			'product_price_currency'     => 'USD',
			'price_checked_date'         => $today,
			'price_region'               => 'Synthetic US market',
			'comparison_set'             => 'Example Comparator TEST-2',
			'major_failures'             => 'No physical product was tested.',
			'fact_check_status'          => 'not_required',
			'medical_review_status'      => 'not_required',
		)
	)
);
if ( $category ) {
	wp_set_post_terms( $review_id, array( (int) $category->term_id ), 'category' );
}

$empty_category = get_term_by( 'slug', 'test-empty-ranking', 'category' );
if ( ! $empty_category ) {
	$created        = wp_insert_term( '[TEST] Empty ranking', 'category', array( 'slug' => 'test-empty-ranking' ) );
	$empty_category = is_wp_error( $created ) ? null : get_term( (int) $created['term_id'], 'category' );
}

$merchant_id = lel_fixture_post( 'lel_affiliate', 'test-approved-merchant', '[TEST] Approved merchant', '', $admin_id );
lel_fixture_meta(
	$merchant_id,
	array(
		'merchant_id'                 => 'TEST-MERCHANT',
		'merchant_name'               => '[TEST] Example Merchant',
		'merchant_domain'             => 'merchant.example.invalid',
		'program_name'                => '[TEST] Synthetic affiliate program',
		'relationship_status'         => 'active',
		'effective_date'              => '2025-01-01',
		'disclosure_language'         => 'Synthetic affiliate relationship for local and CI testing only.',
		'editorial_independence_note' => 'The synthetic relationship cannot alter score or order.',
		'owner_user_id'               => $admin_id,
		'last_verified_date'          => $today,
	)
);

/**
 * Create another approved synthetic test record for ranking coverage.
 *
 * @param string $slug        Post slug for the test record.
 * @param string $product     Synthetic product name.
 * @param int    $tester_id   Tester user ID.
 * @param int    $approver_id Approving user ID.
 * @param string $today       Current UTC date in Y-m-d format.
 * @param array  $results     Public test-result rows.
 * @return int The approved test-record post ID.
 */
function lel_fixture_test_record( string $slug, string $product, int $tester_id, int $approver_id, string $today, array $results ): int {
	$record = lel_fixture_post( 'lel_test_record', $slug, '[TEST] ' . $product . ' test record', '', $tester_id );
	lel_fixture_meta(
		$record,
		array(
			'product_name'          => $product,
			'unit_identifier'       => strtoupper( $slug ),
			'acquisition_method'    => 'purchased',
			'tester_user_ids'       => (string) $tester_id,
			'submitted_by'          => $tester_id,
			'submitted_at'          => gmdate( DATE_ATOM ),
			'test_start_date'       => '2025-02-01',
			'test_end_date'         => '2025-02-03',
			'protocol_id'           => 'TEST-WEARABLE',
			'protocol_version'      => '1.0',
			'raw_observations'      => 'Synthetic observations for interface verification only.',
			'public_test_results'   => $results,
			'measurement_equipment' => 'No physical equipment; software fixture.',
			'failures'              => 'Synthetic record only.',
			'deviations'            => 'Physical testing not applicable.',
			'comparison_devices'    => 'Example Comparator TEST-2.',
			'environment'           => 'Local Docker environment.',
			'evidence_references'   => 'https://example.invalid/test-method',
			'conflicts'             => 'Synthetic test data only.',
			'approval_status'       => 'pending',
		)
	);
	if ( ! \Longevity\Core\Review_Methodology::valid_test_record( $record, '1.0' ) && ! \Longevity\Core\Review_Methodology::approve_test_record( $record, $approver_id ) ) {
		WP_CLI::error( sprintf( 'Synthetic test record %d could not be independently approved.', $record ) );
	}
	return $record;
}

$record_two   = lel_fixture_test_record(
	'test-device-two-record',
	'Example Device TEST-2',
	$tester->ID,
	$test_approver->ID,
	$today,
	array(
		array(
			'label'           => 'Sync reliability',
			'observed_value'  => '9',
			'unit'            => 'of 10 sessions',
			'reference_label' => 'Synthetic target',
			'reference_value' => '9 of 10',
			'status'          => 'meets',
			'note'            => 'Synthetic result.',
			'display_order'   => 10,
		),
	)
);
$record_three = lel_fixture_test_record(
	'test-device-three-record',
	'Example Device TEST-3',
	$tester->ID,
	$test_approver->ID,
	$today,
	array(
		array(
			'label'           => 'Export format',
			'observed_value'  => 'CSV',
			'unit'            => '',
			'reference_label' => '',
			'reference_value' => '',
			'status'          => 'informational',
			'note'            => 'Synthetic result.',
			'display_order'   => 10,
		),
	)
);

$shared_review_meta = array_merge(
	lel_fixture_public_meta( $today, $next_review ),
	array(
		'testing_required'           => true,
		'testing_status'             => 'complete',
		'testing_start_date'         => '2025-02-01',
		'testing_end_date'           => '2025-02-03',
		'testing_duration'           => 'Three synthetic sessions',
		'testing_methodology_url'    => 'https://example.invalid/test-method',
		'testing_protocol_version'   => '1.0',
		'product_acquisition_method' => 'purchased',
		'review_score_version'       => \Longevity\Core\Review_Methodology::model_version(),
		'best_for'                   => 'Automated ranking-interface verification.',
		'not_for'                    => 'Any real purchase or health decision.',
		'comparison_set'             => 'Example Comparator TEST-2',
		'major_failures'             => 'No physical product was tested.',
		'fact_check_status'          => 'not_required',
		'medical_review_status'      => 'not_required',
		'product_brand'              => '[TEST] Example Labs',
		'price_checked_date'         => $today,
		'price_region'               => 'Synthetic US market',
		'product_price_currency'     => 'USD',
	)
);

$review_two     = lel_fixture_post( 'review', 'test-alpha-review', '[TEST] Alpha product report', $review_content . '\n[affiliate_link url="https://merchant.example.invalid/product" label="View synthetic merchant"]', $author->ID );
$dimensions_two = array(
	array(
		'name'   => 'Interface clarity',
		'score'  => 4.6,
		'weight' => 50.0,
	),
	array(
		'name'   => 'Metadata completeness',
		'score'  => 4.2,
		'weight' => 50.0,
	),
);
lel_fixture_meta(
	$review_two,
	array_merge(
		$shared_review_meta,
		array(
			'test_record_id'                => $record_two,
			'review_score'                  => 4.4,
			'review_score_dimensions'       => $dimensions_two,
			'review_score_confidence'       => 'High confidence',
			'tested_product_model'          => 'Example Device TEST-2',
			'product_variant'               => 'Synthetic small',
			'product_price_amount'          => 249.00,
			'commercial_relationship'       => 'affiliate',
			'affiliate_disclosure_required' => true,
			'affiliate_disclosure_status'   => 'draft',
			'affiliate_registry_verified'   => true,
		)
	)
);

$review_three     = lel_fixture_post( 'review', 'test-zeta-review', '[TEST] Zeta product report', $review_content, $author->ID );
$dimensions_three = array(
	array(
		'name'   => 'Interface clarity',
		'score'  => 4.2,
		'weight' => 50.0,
	),
	array(
		'name'   => 'Metadata completeness',
		'score'  => 4.0,
		'weight' => 50.0,
	),
);
lel_fixture_meta(
	$review_three,
	array_merge(
		$shared_review_meta,
		array(
			'test_record_id'          => $record_three,
			'review_score'            => 4.1,
			'review_score_dimensions' => $dimensions_three,
			'review_score_confidence' => 'Preliminary',
			'tested_product_model'    => 'Example Device TEST-3',
			'product_variant'         => 'Synthetic large',
			'product_price_amount'    => 149.00,
		)
	)
);

if ( $category ) {
	wp_set_post_terms( $review_two, array( (int) $category->term_id ), 'category' );
	wp_set_post_terms( $review_three, array( (int) $category->term_id ), 'category' );
}

$blocked_id = lel_fixture_post( 'review', 'test-blocked-review', '[TEST] Blocked incomplete review', $review_content, $admin_id );
lel_fixture_meta(
	$blocked_id,
	array_merge(
		lel_fixture_public_meta( $today, $next_review ),
		array(
			'testing_required'          => true,
			'testing_status'            => 'not_started',
			'tested_product_model'      => 'Example Incomplete Device',
			'comparison_set'            => 'No completed comparison.',
			'editorial_approval_status' => 'testing_incomplete',
		)
	)
);

$correction_id = lel_fixture_post( 'lel_correction', 'test-correction-record', '[TEST] Completed correction', '', $admin_id );
lel_fixture_meta(
	$correction_id,
	array(
		'corrected_post_id'       => $article_id,
		'reported_date'           => $today,
		'reported_by'             => 'Automated synthetic fixture',
		'issue_category'          => 'clarification',
		'issue_description'       => 'Test correction lifecycle and public output.',
		'severity'                => 'minor',
		'public_impact'           => 'No real-world impact; test data only.',
		'assigned_editor_user_id' => $admin_id,
		'correction_status'       => 'complete',
		'resolution'              => 'Confirmed that the correction component renders.',
		'corrected_date'          => $today,
		'public_correction_note'  => 'Synthetic correction notice used to verify the public update history.',
		'reviewer_required'       => false,
		'medical_rereviewed'      => false,
		'conclusion_changed'      => false,
	)
);

// Create all final workflow states through immutable approval services. Approval order is material.
lel_fixture_approve( $article_id, 'editorial', $admin_id );
lel_fixture_approve( $medical_id, 'fact_check', $fact_checker->ID, array( 'claims' => 1 ) );
lel_fixture_approve(
	$medical_id,
	'medical',
	$reviewer->ID,
	array(
		'scope'   => 'full_article',
		'version' => 'test-1.0',
	)
);
lel_fixture_approve( $medical_id, 'editorial', $admin_id );

foreach ( array( $review_id, $review_two, $review_three ) as $tested_review_id ) {
	lel_fixture_approve( $tested_review_id, 'testing', $test_approver->ID );
}
lel_fixture_approve( $review_two, 'commercial', $commercial_approver->ID );
foreach ( array( $review_id, $review_two, $review_three ) as $tested_review_id ) {
	lel_fixture_approve( $tested_review_id, 'editorial', $admin_id );
}

foreach ( array( $article_id, $medical_id, $review_id, $review_two, $review_three ) as $public_id ) {
	$result = wp_update_post(
		array(
			'ID'          => $public_id,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $result ) || 'publish' !== get_post_status( $public_id ) ) {
		WP_CLI::error( sprintf( 'Synthetic fixture %d did not pass its publication gates.', $public_id ) );
	}
}

wp_update_post(
	array(
		'ID'          => $blocked_id,
		'post_status' => 'publish',
	)
);
if ( 'publish' === get_post_status( $blocked_id ) ) {
	WP_CLI::error( 'The intentionally incomplete review bypassed publication controls.' );
}

// --- CI-only public route projection (PRV3-BOOT-03) ---------------------
// Publishes exactly the draft pages that config/routes.json flags as
// ci_fixture_public, through the real trust-page approval gate, so browser,
// accessibility, Lighthouse, and load tests see a deterministic public route
// set on a clean database. Pages are watermarked with _lel_ci_fixture_published
// so the projection stays detectable and can never be mistaken for a human
// production publication.

$lel_contract_path = file_exists( '/project-config/routes.json' )
	? '/project-config/routes.json'
	: dirname( __DIR__ ) . '/config/routes.json';
if ( ! is_readable( $lel_contract_path ) ) {
	WP_CLI::error( 'Route-state contract config/routes.json is not readable; refusing to guess the public route projection.' );
}
$lel_route_contract = json_decode( (string) file_get_contents( $lel_contract_path ), true );
if ( ! is_array( $lel_route_contract ) || empty( $lel_route_contract['pages'] ) ) {
	WP_CLI::error( 'Route-state contract config/routes.json is invalid.' );
}

$trust_approver = lel_fixture_user( 'lel_synthetic_trust_approver', 'trust-approver@example.invalid', '[TEST] Synthetic Trust Page Approver', 'subscriber', array( 'approve_trust_pages' ) );

foreach ( $lel_route_contract['pages'] as $lel_route_key => $lel_route_page ) {
	if ( 'page' !== ( $lel_route_page['type'] ?? '' ) || empty( $lel_route_page['ci_fixture_public'] ) ) {
		continue;
	}
	$lel_page = get_page_by_path( (string) $lel_route_page['slug'], OBJECT, 'page' );
	if ( ! $lel_page instanceof WP_Post ) {
		WP_CLI::error( sprintf( 'Contract route "%s" (/%s/) is missing after bootstrap; run wp longevity bootstrap all first.', $lel_route_key, $lel_route_page['slug'] ) );
	}
	if ( 'publish' === $lel_page->post_status ) {
		continue;
	}
	if ( ! empty( $lel_route_page['trust_page'] ) && null === \Longevity\Core\Trust_Pages::approve( (int) $lel_page->ID, (int) $trust_approver->ID ) ) {
		WP_CLI::error( sprintf( 'Synthetic trust-page approval failed for "%s"; the projection must pass the real gate, not bypass it.', $lel_route_key ) );
	}
	$lel_publish_result = wp_update_post(
		array(
			'ID'          => $lel_page->ID,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $lel_publish_result ) || 'publish' !== get_post_status( $lel_page->ID ) ) {
		WP_CLI::error( sprintf( 'Contract route "%s" did not pass its publication gates in fixture mode.', $lel_route_key ) );
	}
	lel_fixture_meta( (int) $lel_page->ID, array( '_lel_ci_fixture_published' => '1' ) );
	\Longevity\Core\Meta_Authorization::enter_trusted_scope();
	try {
		delete_post_meta( (int) $lel_page->ID, '_longevity_noindex' );
	} finally {
		\Longevity\Core\Meta_Authorization::exit_trusted_scope();
	}
	WP_CLI::log( sprintf( 'Published CI fixture projection for "%s" (/%s/).', $lel_route_key, $lel_route_page['slug'] ) );
}

// bootstrap.sh sets blog_public=0 as a safety default so a fresh site is never
// indexed. CI fixture mode must mirror production-with-indexing-enabled:
// with blog_public=1 WordPress's own noindex logic (drafts, reviews archive,
// search, per-page _longevity_noindex) becomes the only noindex source, which
// is exactly what production-readiness-audit.spec.js verifies. This file is
// refused by the environment guard above on staging/production, so the
// projection can never enable indexing there.
update_option( 'blog_public', 1 );
WP_CLI::log( 'Enabled search-engine visibility for the CI fixture projection (blog_public=1).' );

flush_rewrite_rules( false );
WP_CLI::success( 'Synthetic local/CI fixtures are ready; the incomplete review remained blocked.' );
