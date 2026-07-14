<?php
/** Lightweight fallback tests used when Composer/PHPUnit is unavailable. */
require __DIR__ . '/bootstrap.php';

use Longevity\Core\Meta_Registry;
use Longevity\Core\Publication_Gates;
use Longevity\Core\Review_Methodology;

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

echo "Fallback PHP unit tests passed.\n";
