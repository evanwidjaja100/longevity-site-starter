<?php
/** Lightweight fallback tests used when Composer/PHPUnit is unavailable. */
require __DIR__ . '/bootstrap.php';

use Longevity\Core\Meta_Registry;
use Longevity\Core\Publication_Gates;
use Longevity\Core\Review_Methodology;
use Longevity\Core\Rankings;

$exit_code = 0;

// Run architecture tests first.
$arch_failures = 0;
echo "Running architecture tests...\n";
ob_start();
require __DIR__ . '/ArchitectureTest.php';
$arch_result = ArchitectureTest::run();
$arch_output  = ob_get_clean();
echo $arch_output;
if ( 0 !== $arch_result ) {
	$exit_code = 1;
}

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
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
$assert( 1 === count( $public_results ) && 'Battery' === $public_results[0]['label'] && ! isset( $public_results[0]['private'] ), 'Public result rows must be sanitized and unexpected fields removed.' );

$GLOBALS['lel_test_meta'] = array( 1 => array( 'review_score' => 4, 'review_score_confidence' => 'Low confidence', 'last_material_update' => '2026-07-01' ), 2 => array( 'review_score' => 4, 'review_score_confidence' => 'High confidence', 'last_material_update' => '2026-07-01' ) );
$GLOBALS['lel_test_titles'] = array( 1 => 'Beta', 2 => 'Alpha' );
$ranked = Rankings::sort( array( (object) array( 'ID' => 1 ), (object) array( 'ID' => 2 ) ) );
$assert( 2 === $ranked[0]->ID, 'Confidence must provide the deterministic score tiebreaker.' );

$context = array(
	'post_type' => 'post',
	'content' => 'Complete content.',
	'author_present' => true,
	'featured_image_alt_present' => true,
	'content_summary' => 'A direct answer with enough detail.',
	'content_limitations' => 'A clear statement of meaningful limitations.',
	'next_content_review_date' => '2027-01-14',
	'commercial_relationship' => 'none',
	'editorial_approval_status' => 'ready',
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
