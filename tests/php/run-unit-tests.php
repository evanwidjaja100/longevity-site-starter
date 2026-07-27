<?php
/** Lightweight fallback tests used when Composer/PHPUnit is unavailable. */
require __DIR__ . '/bootstrap.php';

use Longevity\Core\Meta_Registry;
use Longevity\Core\Publication_Gates;
use Longevity\Core\Review_Methodology;
use Longevity\Core\Rankings;
use Longevity\Core\Date_Validator;
use Longevity\Core\Meta_Authorization;
use Longevity\Core\Reviewer_Credentials;
use Longevity\Core\Approval_Fingerprint;
use Longevity\Core\Affiliate_Registry;
use Longevity\Core\Freshness_Repository;
use Longevity\Core\Rest_API;
use Longevity\Core\Admin_UI;
use Longevity\Core\Claims;

$exit_code = 0;

// Run architecture tests first.
$arch_failures = 0;
echo "Running architecture tests...\n";
ob_start();
require_once __DIR__ . '/support/ArchitectureAssertions.php';
$arch_failures = \Longevity\Core\Tests\Support\ArchitectureAssertions::failures();
foreach ( $arch_failures as $failure ) {
	echo "[ARCH FAIL] {$failure}\n";
}
if ( ! $arch_failures ) {
	echo "[ARCH OK] All architecture constraints pass.\n";
}
$arch_output = ob_get_clean();
echo $arch_output;
if ( $arch_failures ) {
	$exit_code = 1;
}

$failures = array();
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$score = Review_Methodology::calculate_score(
	array(
		array( 'name' => 'A', 'score' => 4, 'weight' => 40 ),
		array( 'name' => 'B', 'score' => 3, 'weight' => 60 ),
	)
);
$assert( 3.4 === $score['score'], 'Weighted score must equal 3.4.' );
$assert( 2 === count( $score['dimensions'] ), 'Calculated score must retain raw dimensions.' );
$assert( 'U' === Meta_Registry::sanitize_value( 'evidence_grade', 'U' ), 'Evidence grade U must be accepted.' );
$assert( '' === Meta_Registry::sanitize_value( 'date', 'July 14' ), 'Non-ISO date must be rejected.' );
$public_results = Review_Methodology::sanitize_public_results( array( array( 'label' => '<b>Battery</b>', 'observed_value' => '6.2', 'status' => 'meets', 'private' => 'drop' ) ) );
$assert( 1 === count( $public_results['rows'] ) && 'Battery' === $public_results['rows'][0]['label'] && ! isset( $public_results['rows'][0]['private'] ), 'Public result rows must be sanitized and unexpected fields removed.' );

$GLOBALS['lel_test_meta'] = array( 1 => array( 'review_score' => 4, 'review_score_confidence' => 'Low confidence', 'last_material_update' => '2026-07-01' ), 2 => array( 'review_score' => 4, 'review_score_confidence' => 'High confidence', 'last_material_update' => '2026-07-01' ) );
$GLOBALS['lel_test_titles'] = array( 1 => 'Beta', 2 => 'Alpha' );
$ranked = Rankings::sort( array( (object) array( 'ID' => 1 ), (object) array( 'ID' => 2 ) ) );
$assert( 2 === $ranked[0]->ID, 'Confidence must provide the deterministic score tiebreaker.' );

$context = array(
	'as_of_date' => '2026-07-21',
	'post_type' => 'post',
	'content' => 'Complete content.',
	'author_present' => true,
	'featured_image_alt_present' => true,
	'content_summary' => 'A direct answer with enough detail.',
	'content_limitations' => 'A clear statement of meaningful limitations.',
	'next_content_review_date' => '2027-01-14',
	'commercial_relationship' => 'none',
	'editorial_approval_status' => 'ready',
	'editorial_approval_current' => true,
	'material_health_claims' => false,
	'medical_review_required' => false,
	'testing_required' => false,
	'affiliate_links_present' => false,
	'original_contribution' => 'A decision framework.',
	'region_scope' => 'Global',
	'uncertainty_statement_present' => true,
	'claim_count' => 0,
	'verified_claim_count' => 0,
	'source_count' => 0,
	'evidence_grade' => '',
);
$assert( ! Publication_Gates::evaluate_values( $context )->is_blocked(), 'Complete low-risk content should pass.' );
$context['content_summary'] = '';
$assert( Publication_Gates::evaluate_values( $context )->is_blocked(), 'Missing summary should block.' );


$assert( Date_Validator::is_valid( '2024-02-29' ), 'Leap-day validation must accept a real calendar date.' );
$assert( ! Date_Validator::is_valid( '2025-02-29' ), 'Date validation must reject impossible calendar dates.' );
$assert( Approval_Fingerprint::hash( array( 'b' => "x\r\n", 'a' => 1 ) ) === Approval_Fingerprint::hash( array( 'a' => 1, 'b' => "x\n" ) ), 'Approval fingerprints must normalize map order and line endings.' );
$approval_post = new WP_Post();
$approval_post->ID = 60;
$approval_post->post_type = 'post';
$approval_post->post_title = 'Reviewed title';
$approval_post->post_excerpt = 'Reviewed excerpt';
$approval_post->post_content = 'Reviewed content';
$approval_post->post_author = '9';
$GLOBALS['lel_test_posts'][60] = $approval_post;
$GLOBALS['lel_test_meta'][60] = array( 'next_content_review_date' => '2027-01-01', 'editorial_approval_status' => 'draft' );
$before_projection = Approval_Fingerprint::build( 60, 'editorial' );
$GLOBALS['lel_test_meta'][60]['editorial_approval_status'] = 'ready';
$after_projection = Approval_Fingerprint::build( 60, 'editorial' );
$assert( $before_projection['combined_hash'] === $after_projection['combined_hash'], 'Service-projected approval status must not stale its own immutable snapshot.' );

$GLOBALS['lel_test_user_caps'][7] = array( 'edit_post' );
$assert( Meta_Authorization::can_write( 'content_summary', 9, 7, 'rest' ), 'A post editor must retain descriptive-field access.' );
$assert( ! Meta_Authorization::can_write( 'medical_review_required', 9, 7, 'rest' ), 'A post editor must not lower medical-review requirements.' );
$assert( ! Meta_Authorization::can_write( 'unknown_field', 9, 7, 'rest' ), 'Unknown metadata policies must deny by default.' );
$GLOBALS['lel_test_user_caps'][7][] = 'approve_publication';
$assert( ! Meta_Authorization::can_write( 'editorial_approval_status', 9, 7, 'rest' ), 'REST must not write final workflow projection fields even for approvers.' );

$admin_post = new WP_Post();
$admin_post->ID = 61;
$admin_post->post_type = 'post';
$GLOBALS['lel_test_current_user_id'] = 7;
$GLOBALS['lel_test_current_user_caps'] = array( 'edit_post', 'approve_publication' );
$GLOBALS['lel_test_editable_posts'] = array( 61 );
$GLOBALS['lel_test_meta'][61] = array( 'editorial_approval_status' => 'editorial_review' );
$_POST = array(
	'longevity_editorial_nonce' => 'test_nonce_longevity_save_editorial',
	'lel_present' => array( 'editorial_approval_status' => '1' ),
	'editorial_approval_status' => 'ready',
);
Admin_UI::save_editorial_meta( 61, $admin_post, false );
$assert( 'editorial_review' === get_post_meta( 61, 'editorial_approval_status', true ), 'Classic editor must not persist a final editorial state without an approval snapshot.' );
$_POST = array();

$claim_post = new WP_Post();
$claim_post->ID = 62;
$claim_post->post_type = 'lel_claim';
$GLOBALS['lel_test_posts'][62] = $claim_post;
$GLOBALS['lel_test_meta'][62] = array( 'claim_id' => 'FALLBACK-62', 'claim_text' => 'Synthetic claim.', 'source_url' => 'https://example.invalid/source', 'last_edited_by' => 7, 'verification_status' => 'not_verified' );
$GLOBALS['lel_test_user_caps'][7][] = 'verify_claims';
$GLOBALS['lel_test_user_caps'][11] = array( 'verify_claims' );
$assert( ! Claims::verify( 62, 7 ), 'The last claim editor must not verify the same claim.' );
$assert( Claims::verify( 62, 11 ) && 'verified' === get_post_meta( 62, 'verification_status', true ), 'An independent claim verifier must create a verified snapshot.' );

$GLOBALS['lel_test_user_caps'][8] = array( 'complete_medical_review', 'verify_reviewer_credentials' );
$assert( ! Reviewer_Credentials::can_verify( 8, 8 ), 'A reviewer must not verify their own credentials.' );
$GLOBALS['lel_test_user_caps'][3] = array( 'verify_reviewer_credentials' );
$assert( Reviewer_Credentials::can_verify( 3, 8 ), 'An independent authorized verifier may verify a reviewer.' );
$GLOBALS['lel_test_user_meta'][8] = array(
	'credential_verification_status' => 'verified',
	'credential_verification_date' => '2026-01-01',
	'credential_expiration_date' => '2027-01-01',
	'credential_verified_by_user_id' => 3,
	'verified_professional_credentials' => 'MD',
	'verified_review_scope' => 'safety_only,full_article',
	'verified_jurisdictions' => 'US,Global',
	'credential_verification_version' => Reviewer_Credentials::VERSION,
);
$assert( Reviewer_Credentials::is_valid_for( 8, 'safety_only', 'US', '2026-07-21' ), 'Current independently verified reviewer scope must pass.' );
$assert( ! Reviewer_Credentials::is_valid_for( 8, 'dosage_language_only', 'US', '2026-07-21' ), 'Reviewer scope mismatch must fail.' );

$affiliate_record = array( 'merchant_domain' => 'example.com', 'relationship_status' => 'active', 'effective_date' => '2026-01-01', 'expiration_date' => '2026-12-31', 'last_verified_date' => '2026-07-01', 'allow_subdomains' => false );
$assert( Affiliate_Registry::relationship_is_eligible( $affiliate_record, 'https://www.example.com/item', '2026-07-21' ), 'Eligible exact affiliate destination must pass.' );
$assert( ! Affiliate_Registry::relationship_is_eligible( $affiliate_record, 'https://example.com.evil.test/item', '2026-07-21' ), 'Deceptive suffix destination must fail.' );
$assert( null === Affiliate_Registry::normalize_destination( 'https://user:pass@example.com/' ), 'URL credentials must be rejected.' );
$assert( null === Affiliate_Registry::normalize_destination( 'https://example.com:8443/' ), 'Unsupported affiliate ports must be rejected.' );

$protocol = new WP_Post();
$protocol->ID = 50;
$protocol->post_type = 'lel_protocol';
$protocol->post_author = '40';
$GLOBALS['lel_test_posts'][50] = $protocol;
$GLOBALS['lel_test_meta'][50] = array(
	'protocol_id' => 'fallback-protocol', 'protocol_version' => '1.0', 'product_category' => 'wearables',
	'effective_date' => '2026-01-01', 'retired_date' => '', 'minimum_test_duration' => '14 days',
	'required_observations' => 'Observations', 'required_comparison_methods' => 'Comparison',
	'required_environmental_conditions' => 'Conditions', 'required_disclosure_fields' => 'Disclosures',
	'scoring_dimensions' => array( array( 'name' => 'Accuracy', 'score' => 4, 'weight' => 100 ) ),
	'known_limitations' => 'Limitations',
);
$GLOBALS['lel_test_user_caps'][40] = array( 'approve_test_records' );
$GLOBALS['lel_test_user_caps'][41] = array( 'approve_test_records' );
$assert( ! Review_Methodology::approve_protocol( 50, 40 ), 'A protocol author must not self-approve.' );
$assert( Review_Methodology::approve_protocol( 50, 41 ), 'An independent test approver may approve a complete protocol.' );
$protocol_hash = (string) get_post_meta( 50, 'approval_snapshot_hash', true );
$GLOBALS['lel_test_meta'][50]['known_limitations'] = 'Changed after approval';
$assert( $protocol_hash !== Review_Methodology::protocol_fingerprint( 50 ), 'Protocol approval must be bound to exact material state.' );

$fair = Freshness_Repository::select_fair_batch( array( array( 'id' => 4, 'last_scanned_at' => '2026-07-20 00:00:00' ), array( 'id' => 2, 'last_scanned_at' => '' ), array( 'id' => 3, 'last_scanned_at' => '2026-07-01 00:00:00' ), array( 'id' => 1, 'last_scanned_at' => '' ) ), 3 );
$assert( array( 1, 2, 3 ) === array_column( $fair, 'id' ), 'Freshness selection must prefer never-scanned and then least-recently-scanned records.' );
$assert( array( 'status' => 'ok' ) === Rest_API::health()->get_data(), 'Public health must expose liveness only.' );

echo sprintf( "Fallback PHP checks executed: %d assertions plus architecture constraints.\n", $checks );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: $failure\n" );
	}
	exit( 1 );
}

if ( 0 === $exit_code ) {
	echo "Fallback PHP unit tests passed.\n";
}
exit( $exit_code );
